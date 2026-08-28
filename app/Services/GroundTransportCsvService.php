<?php

namespace App\Services;

use App\Models\GroundTransportImport;
use App\Models\GroundTransportRate;
use App\Models\GroundTransportRegulation;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GroundTransportCsvService
{
    private ?Collection $catalog = null;

    public const REGULATION_NUMBER = 'PMK Nomor 32 Tahun 2025';
    public const FISCAL_YEAR = 2026;
    public const SOURCE_REFERENCE = 'PMK Nomor 32 Tahun 2025, Lampiran II Tabel 1-2, halaman 62-68';
    public const MAX_BYTES = 2_097_152;
    public const EXPECTED_ROUTES = 370;

    public const HEADERS = [
        'nama_provinsi',
        'ibukota_provinsi',
        'kabupaten_kota_tujuan',
        'tarif_one_way',
    ];

    public function createValidatedImport(UploadedFile $file, User $uploader): GroundTransportImport
    {
        $this->cleanupExpired();
        $rows = $this->parse($file);
        $checksum = hash_file('sha256', $file->getRealPath());

        if (! is_string($checksum)) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'Checksum file CSV tidak dapat dihitung.',
            ]);
        }

        $id = (string) Str::uuid();
        $path = $file->storeAs('pmk/ground-transport/imports/temporary', $id.'.csv', 'local');
        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'File CSV belum dapat disimpan.',
            ]);
        }

        try {
            return GroundTransportImport::query()->create([
                'id' => $id,
                'status' => GroundTransportImport::STATUS_VALIDATED,
                'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                'temporary_path' => $path,
                'csv_sha256' => $checksum,
                'parsed_rows' => $rows,
                'uploaded_by' => $uploader->id,
                'expires_at' => now()->addDay(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function commit(GroundTransportImport $import): GroundTransportRegulation
    {
        if ($import->status !== GroundTransportImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'Pratinjau CSV sudah tidak berlaku.',
            ]);
        }

        $temporaryPath = (string) $import->temporary_path;
        if ($temporaryPath === '' || ! Storage::disk('local')->exists($temporaryPath)) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'File CSV sementara tidak ditemukan.',
            ]);
        }
        if (hash_file('sha256', Storage::disk('local')->path($temporaryPath)) !== $import->csv_sha256) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'File CSV berubah setelah divalidasi.',
            ]);
        }

        $archivePath = 'pmk/ground-transport/imports/archive/'.$import->csv_sha256.'.csv';
        $archiveCreated = false;

        try {
            $regulation = DB::transaction(function () use (
                $import,
                $temporaryPath,
                $archivePath,
                &$archiveCreated
            ): GroundTransportRegulation {
                $lockedImport = GroundTransportImport::query()->lockForUpdate()->findOrFail($import->id);
                if ($lockedImport->status !== GroundTransportImport::STATUS_VALIDATED) {
                    throw ValidationException::withMessages([
                        'ground_transport_csv' => 'Dataset CSV ini sudah diproses.',
                    ]);
                }
                if (GroundTransportRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->where('csv_sha256', $lockedImport->csv_sha256)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'ground_transport_csv' => 'Dataset CSV yang sama sudah pernah disimpan.',
                    ]);
                }
                if (! Storage::disk('local')->copy($temporaryPath, $archivePath)) {
                    throw new RuntimeException('Arsip CSV transportasi darat PMK tidak dapat dibuat.');
                }
                $archiveCreated = true;

                $revision = ((int) GroundTransportRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->orderByDesc('revision')
                    ->lockForUpdate()
                    ->value('revision')) + 1;

                $regulation = GroundTransportRegulation::query()->create([
                    'regulation_number' => self::REGULATION_NUMBER,
                    'fiscal_year' => self::FISCAL_YEAR,
                    'revision' => $revision,
                    'source_reference' => self::SOURCE_REFERENCE,
                    'status' => GroundTransportRegulation::STATUS_DRAFT,
                    'original_filename' => $lockedImport->original_filename,
                    'csv_path' => $archivePath,
                    'csv_sha256' => $lockedImport->csv_sha256,
                    'uploaded_by' => $lockedImport->uploaded_by,
                ]);

                $provinceIds = Province::query()->pluck('id', 'code');
                $now = now();
                GroundTransportRate::query()->insert(
                    collect($lockedImport->parsed_rows)->map(fn (array $row): array => [
                        'ground_transport_regulation_id' => $regulation->id,
                        'province_id' => $provinceIds[$row['kode_provinsi']],
                        'capital_city' => $row['ibukota_provinsi'],
                        'destination_city' => $row['kabupaten_kota_tujuan'],
                        'capital_key' => $row['capital_key'],
                        'destination_key' => $row['destination_key'],
                        'one_way_amount' => $row['amount'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );

                $lockedImport->update([
                    'status' => GroundTransportImport::STATUS_COMMITTED,
                    'temporary_path' => null,
                    'ground_transport_regulation_id' => $regulation->id,
                ]);

                return $regulation;
            });
        } catch (Throwable $exception) {
            if ($archiveCreated) {
                Storage::disk('local')->delete($archivePath);
            }
            throw $exception;
        }

        Storage::disk('local')->delete($temporaryPath);

        return $regulation;
    }

    public function discard(GroundTransportImport $import): void
    {
        if ($import->status !== GroundTransportImport::STATUS_VALIDATED) {
            return;
        }
        if ($import->temporary_path) {
            Storage::disk('local')->delete($import->temporary_path);
        }
        $import->update([
            'status' => GroundTransportImport::STATUS_DISCARDED,
            'temporary_path' => null,
            'parsed_rows' => [],
        ]);
    }

    public function cleanupExpired(): void
    {
        GroundTransportImport::query()
            ->where('status', GroundTransportImport::STATUS_VALIDATED)
            ->where('expires_at', '<', now())
            ->orderBy('created_at')
            ->chunkById(50, function ($imports): void {
                foreach ($imports as $import) {
                    if ($import->temporary_path) {
                        Storage::disk('local')->delete($import->temporary_path);
                    }
                    $import->update([
                        'status' => GroundTransportImport::STATUS_EXPIRED,
                        'temporary_path' => null,
                        'parsed_rows' => [],
                    ]);
                }
            }, 'id');
    }

    /** @return array<int, array<string, int|string>> */
    public function parse(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if (! is_string($content) || $content === '') {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'File CSV kosong atau tidak dapat dibaca.',
            ]);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'Ukuran CSV maksimal 2 MB.',
            ]);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'CSV harus menggunakan encoding UTF-8.',
            ]);
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = trim((string) strtok($content, "\r\n"));
        $delimiter = match ($firstLine) {
            implode(',', self::HEADERS) => ',',
            implode(';', self::HEADERS) => ';',
            default => null,
        };
        if ($delimiter === null) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'Header CSV tidak sesuai template transportasi darat resmi.',
            ]);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Stream validasi CSV tidak dapat dibuat.');
        }
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $records[] = array_map(fn ($value): string => trim((string) $value), $row);
            if (count($records) > self::EXPECTED_ROUTES + 1) {
                break;
            }
        }
        fclose($stream);

        if (array_shift($records) !== self::HEADERS) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => 'Header CSV tidak sesuai template transportasi darat resmi.',
            ]);
        }

        $provinces = Province::query()->get();
        if ($provinces->count() !== 38) {
            throw new RuntimeException('Master provinsi belum lengkap.');
        }
        $byName = $provinces->keyBy(fn (Province $province): string => $this->normalizeName($province->name));
        $aliases = ['BANGKABELITUNG' => 'KEPULAUANBANGKABELITUNG'];
        $catalog = $this->catalogRows()->keyBy(fn (array $row): string => $this->routeKey(
            $row['kode_provinsi'],
            $row['ibukota_provinsi'],
            $row['kabupaten_kota_tujuan']
        ));

        $rows = [];
        $seen = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $line = $index + 2;
            if (count($record) !== 4) {
                $errors[] = "Baris {$line}: jumlah kolom harus empat.";
                continue;
            }
            [$provinceName, $capital, $destination, $rawAmount] = $record;
            $provinceKey = $aliases[$this->normalizeName($provinceName)] ?? $this->normalizeName($provinceName);
            $province = $byName->get($provinceKey);
            if (! $province) {
                $errors[] = "Baris {$line}: provinsi {$provinceName} tidak dikenal.";
                continue;
            }

            $key = $this->routeKey($province->code, $capital, $destination);
            $catalogRow = $catalog->get($key);
            if (! $catalogRow) {
                $errors[] = "Baris {$line}: rute {$capital} - {$destination} tidak ada pada template resmi.";
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = "Baris {$line}: rute {$capital} - {$destination} duplikat.";
                continue;
            }
            if (! preg_match('/^\d+$/', $rawAmount) || (int) $rawAmount <= 0 || (int) $rawAmount > 99_999_999) {
                $errors[] = "Baris {$line}: tarif harus bilangan Rupiah positif tanpa pemisah.";
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'kode_provinsi' => $province->code,
                'nama_provinsi' => $province->name,
                'ibukota_provinsi' => $catalogRow['ibukota_provinsi'],
                'kabupaten_kota_tujuan' => $catalogRow['kabupaten_kota_tujuan'],
                'capital_key' => $this->normalizeLocation($catalogRow['ibukota_provinsi']),
                'destination_key' => $this->normalizeLocation($catalogRow['kabupaten_kota_tujuan']),
                'amount' => (int) $rawAmount,
            ];
        }

        $missing = $catalog->keys()->diff(array_keys($seen));
        if ($missing->isNotEmpty()) {
            $errors[] = 'Masih ada '.$missing->count().' rute template yang belum diisi.';
        }
        if (count($rows) !== self::EXPECTED_ROUTES) {
            $errors[] = 'CSV harus memuat tepat '.self::EXPECTED_ROUTES.' rute yang valid.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'ground_transport_csv' => array_slice(array_values(array_unique($errors)), 0, 12),
            ]);
        }

        return $rows;
    }

    /** @return Collection<int, array<string, int|string>> */
    public function catalogRows(): Collection
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $stream = fopen(resource_path('pmk_ground_transport_routes.csv'), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Katalog rute transportasi darat tidak ditemukan.');
        }

        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if ($headers !== ['kode_provinsi', 'ibukota_provinsi', 'kabupaten_kota_tujuan', 'tarif_sumber']) {
            fclose($stream);
            throw new RuntimeException('Format katalog rute transportasi darat tidak valid.');
        }

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if (count($row) !== 4) {
                continue;
            }
            $rows[] = [
                'kode_provinsi' => trim((string) $row[0]),
                'ibukota_provinsi' => trim((string) $row[1]),
                'kabupaten_kota_tujuan' => trim((string) $row[2]),
                'tarif_sumber' => (int) $row[3],
            ];
        }
        fclose($stream);

        if (count($rows) !== self::EXPECTED_ROUTES) {
            throw new RuntimeException('Katalog rute transportasi darat harus memuat tepat 370 rute.');
        }

        return $this->catalog = collect($rows);
    }

    public function findRate(
        GroundTransportRegulation $regulation,
        string $origin,
        string $destination
    ): ?GroundTransportRate {
        $originCandidates = $this->locationCandidates($origin);
        $destinationCandidates = $this->locationCandidates($destination);

        $matches = GroundTransportRate::query()
            ->where('ground_transport_regulation_id', $regulation->id)
            ->where(function ($query) use ($originCandidates, $destinationCandidates): void {
                $query->where(function ($forward) use ($originCandidates, $destinationCandidates): void {
                    $forward->whereIn('capital_key', $originCandidates)
                        ->whereIn('destination_key', $destinationCandidates);
                })->orWhere(function ($reverse) use ($originCandidates, $destinationCandidates): void {
                    $reverse->whereIn('destination_key', $originCandidates)
                        ->whereIn('capital_key', $destinationCandidates);
                });
            })
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function normalizeLocation(string $name): string
    {
        $upper = mb_strtoupper(trim($name), 'UTF-8');
        $upper = preg_replace('/\bKABUPATEN\b/u', 'KAB', $upper) ?? $upper;

        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }

    /**
     * Menentukan provinsi geografis suatu tujuan. Rute Jakarta menuju wilayah
     * sekitar tetap menggunakan provinsi tujuan (misalnya Bogor = Jawa Barat),
     * bukan provinsi asal rutenya.
     */
    public function provinceCodeForLocation(string $location, string $fallbackCode): string
    {
        $locationKey = $this->normalizeLocation($location);
        $codes = $this->catalogRows()
            ->filter(fn (array $row): bool =>
                $this->normalizeLocation($row['ibukota_provinsi']) === $locationKey
                || $this->normalizeLocation($row['kabupaten_kota_tujuan']) === $locationKey
            )
            ->pluck('kode_provinsi')
            ->unique()
            ->values();

        if ($codes->count() === 1) {
            return (string) $codes->first();
        }

        // Sembilan rute kawasan Jakarta muncul juga pada provinsi geografisnya.
        $outsideJakarta = $codes->reject(fn (string $code): bool => $code === '31')->values();
        if ($outsideJakarta->count() === 1) {
            return (string) $outsideJakarta->first();
        }

        return $fallbackCode;
    }

    private function normalizeName(string $name): string
    {
        $upper = mb_strtoupper(trim($name), 'UTF-8');

        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }

    private function routeKey(string $provinceCode, string $capital, string $destination): string
    {
        return $provinceCode.'|'.$this->normalizeLocation($capital).'|'.$this->normalizeLocation($destination);
    }

    /** @return array<int, string> */
    private function locationCandidates(string $location): array
    {
        $normalized = $this->normalizeLocation($location);
        $base = preg_replace('/^(KAB|KOTA)/', '', $normalized) ?? $normalized;

        if (str_starts_with($normalized, 'KAB') || str_starts_with($normalized, 'KOTA')) {
            return array_values(array_unique([$normalized, $base]));
        }

        return array_values(array_unique([
            $normalized,
            $base,
            'KAB'.$base,
            'KOTA'.$base,
        ]));
    }
}
