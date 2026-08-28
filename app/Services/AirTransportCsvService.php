<?php

namespace App\Services;

use App\Models\AirTransportImport;
use App\Models\AirTransportRegulation;
use App\Models\DomesticAirfareRate;
use App\Models\Province;
use App\Models\TerminalTransportRate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AirTransportCsvService
{
    public const REGULATION_NUMBER = 'PMK Nomor 32 Tahun 2025';
    public const FISCAL_YEAR = 2026;
    public const SOURCE_REFERENCE = 'PMK Nomor 32 Tahun 2025, Lampiran II Tabel 16-17, halaman 85-90';
    public const EXPECTED_TERMINAL_RATES = 34;
    public const EXPECTED_AIRFARE_ROUTES = 316;
    public const MAX_BYTES = 2_097_152;

    public const TERMINAL_HEADERS = ['nama_provinsi', 'tarif_orang_kali'];
    public const AIRFARE_HEADERS = ['kota_asal', 'kota_tujuan', 'tarif_bisnis_pp', 'tarif_ekonomi_pp'];

    private ?Collection $terminalCatalog = null;
    private ?Collection $airfareCatalog = null;

    public function createValidatedImport(
        UploadedFile $terminalFile,
        UploadedFile $airfareFile,
        User $uploader
    ): AirTransportImport {
        $this->cleanupExpired();
        $terminalRows = $this->parseTerminal($terminalFile);
        $airfareRows = $this->parseAirfare($airfareFile);
        $terminalChecksum = hash_file('sha256', $terminalFile->getRealPath());
        $airfareChecksum = hash_file('sha256', $airfareFile->getRealPath());
        if (! is_string($terminalChecksum) || ! is_string($airfareChecksum)) {
            throw ValidationException::withMessages(['air_transport_csv' => 'Checksum CSV tidak dapat dihitung.']);
        }

        $id = (string) Str::uuid();
        $terminalPath = $terminalFile->storeAs('pmk/air-transport/imports/temporary', $id.'-terminal.csv', 'local');
        $airfarePath = $airfareFile->storeAs('pmk/air-transport/imports/temporary', $id.'-airfare.csv', 'local');
        if (! is_string($terminalPath) || ! is_string($airfarePath)) {
            Storage::disk('local')->delete(array_filter([$terminalPath, $airfarePath]));
            throw ValidationException::withMessages(['air_transport_csv' => 'Pasangan CSV belum dapat disimpan.']);
        }

        try {
            return AirTransportImport::query()->create([
                'id' => $id,
                'status' => AirTransportImport::STATUS_VALIDATED,
                'terminal_original_filename' => Str::limit($terminalFile->getClientOriginalName(), 255, ''),
                'terminal_temporary_path' => $terminalPath,
                'terminal_csv_sha256' => $terminalChecksum,
                'terminal_rows' => $terminalRows,
                'airfare_original_filename' => Str::limit($airfareFile->getClientOriginalName(), 255, ''),
                'airfare_temporary_path' => $airfarePath,
                'airfare_csv_sha256' => $airfareChecksum,
                'airfare_rows' => $airfareRows,
                'uploaded_by' => $uploader->id,
                'expires_at' => now()->addDay(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete([$terminalPath, $airfarePath]);
            throw $exception;
        }
    }

    public function commit(AirTransportImport $import): AirTransportRegulation
    {
        if ($import->status !== AirTransportImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            throw ValidationException::withMessages(['air_transport_csv' => 'Pratinjau pasangan CSV sudah tidak berlaku.']);
        }

        $terminalTemporary = (string) $import->terminal_temporary_path;
        $airfareTemporary = (string) $import->airfare_temporary_path;
        foreach ([[$terminalTemporary, $import->terminal_csv_sha256], [$airfareTemporary, $import->airfare_csv_sha256]] as [$path, $checksum]) {
            if ($path === '' || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['air_transport_csv' => 'Salah satu CSV sementara tidak ditemukan.']);
            }
            if (hash_file('sha256', Storage::disk('local')->path($path)) !== $checksum) {
                throw ValidationException::withMessages(['air_transport_csv' => 'Salah satu CSV berubah setelah divalidasi.']);
            }
        }

        $terminalArchive = 'pmk/air-transport/imports/archive/'.$import->terminal_csv_sha256.'-terminal.csv';
        $airfareArchive = 'pmk/air-transport/imports/archive/'.$import->airfare_csv_sha256.'-airfare.csv';
        $created = [];
        try {
            $regulation = DB::transaction(function () use (
                $import, $terminalTemporary, $airfareTemporary, $terminalArchive, $airfareArchive, &$created
            ): AirTransportRegulation {
                $locked = AirTransportImport::query()->lockForUpdate()->findOrFail($import->id);
                if ($locked->status !== AirTransportImport::STATUS_VALIDATED) {
                    throw ValidationException::withMessages(['air_transport_csv' => 'Dataset ini sudah diproses.']);
                }
                if (AirTransportRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->where('terminal_csv_sha256', $locked->terminal_csv_sha256)
                    ->where('airfare_csv_sha256', $locked->airfare_csv_sha256)
                    ->exists()) {
                    throw ValidationException::withMessages(['air_transport_csv' => 'Pasangan dataset yang sama sudah pernah disimpan.']);
                }

                foreach ([[$terminalTemporary, $terminalArchive], [$airfareTemporary, $airfareArchive]] as [$from, $to]) {
                    if (! Storage::disk('local')->exists($to)) {
                        if (! Storage::disk('local')->copy($from, $to)) {
                            throw new RuntimeException('Arsip CSV transportasi udara tidak dapat dibuat.');
                        }
                        $created[] = $to;
                    }
                }

                $revision = ((int) AirTransportRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->orderByDesc('revision')->lockForUpdate()->value('revision')) + 1;
                $regulation = AirTransportRegulation::query()->create([
                    'regulation_number' => self::REGULATION_NUMBER,
                    'fiscal_year' => self::FISCAL_YEAR,
                    'revision' => $revision,
                    'source_reference' => self::SOURCE_REFERENCE,
                    'status' => AirTransportRegulation::STATUS_DRAFT,
                    'terminal_original_filename' => $locked->terminal_original_filename,
                    'terminal_csv_path' => $terminalArchive,
                    'terminal_csv_sha256' => $locked->terminal_csv_sha256,
                    'airfare_original_filename' => $locked->airfare_original_filename,
                    'airfare_csv_path' => $airfareArchive,
                    'airfare_csv_sha256' => $locked->airfare_csv_sha256,
                    'uploaded_by' => $locked->uploaded_by,
                ]);

                $provinceIds = Province::query()->pluck('id', 'code');
                $now = now();
                TerminalTransportRate::query()->insert(collect($locked->terminal_rows)->map(fn (array $row): array => [
                    'air_transport_regulation_id' => $regulation->id,
                    'province_id' => $provinceIds[$row['kode_provinsi']],
                    'amount' => $row['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
                DomesticAirfareRate::query()->insert(collect($locked->airfare_rows)->map(fn (array $row): array => [
                    'air_transport_regulation_id' => $regulation->id,
                    'source_number' => $row['source_number'],
                    'origin_city' => $row['kota_asal'],
                    'destination_city' => $row['kota_tujuan'],
                    'origin_key' => $row['origin_key'],
                    'destination_key' => $row['destination_key'],
                    'business_amount' => $row['business_amount'],
                    'economy_amount' => $row['economy_amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
                $locked->update([
                    'status' => AirTransportImport::STATUS_COMMITTED,
                    'terminal_temporary_path' => null,
                    'airfare_temporary_path' => null,
                    'air_transport_regulation_id' => $regulation->id,
                ]);

                return $regulation;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($created);
            throw $exception;
        }

        Storage::disk('local')->delete([$terminalTemporary, $airfareTemporary]);

        return $regulation;
    }

    public function discard(AirTransportImport $import): void
    {
        if ($import->status !== AirTransportImport::STATUS_VALIDATED) {
            return;
        }
        Storage::disk('local')->delete(array_filter([
            $import->terminal_temporary_path,
            $import->airfare_temporary_path,
        ]));
        $import->update([
            'status' => AirTransportImport::STATUS_DISCARDED,
            'terminal_temporary_path' => null,
            'airfare_temporary_path' => null,
            'terminal_rows' => [],
            'airfare_rows' => [],
        ]);
    }

    public function cleanupExpired(): void
    {
        AirTransportImport::query()
            ->where('status', AirTransportImport::STATUS_VALIDATED)
            ->where('expires_at', '<', now())
            ->orderBy('created_at')
            ->chunkById(50, function ($imports): void {
                foreach ($imports as $import) {
                    Storage::disk('local')->delete(array_filter([
                        $import->terminal_temporary_path,
                        $import->airfare_temporary_path,
                    ]));
                    $import->update([
                        'status' => AirTransportImport::STATUS_EXPIRED,
                        'terminal_temporary_path' => null,
                        'airfare_temporary_path' => null,
                        'terminal_rows' => [],
                        'airfare_rows' => [],
                    ]);
                }
            }, 'id');
    }

    /** @return array<int, array<string, int|string>> */
    public function parseTerminal(UploadedFile $file): array
    {
        $records = $this->readCsv($file, self::TERMINAL_HEADERS, 'terminal_transport_csv', self::EXPECTED_TERMINAL_RATES);
        $provinces = Province::query()->get();
        $byName = $provinces->keyBy(fn (Province $province): string => $this->normalizeName($province->name));
        $aliases = ['BANGKABELITUNG' => 'KEPULAUANBANGKABELITUNG'];
        $sourceAliases = ['KEPULAUANBANGKABELITUNG' => 'BANGKABELITUNG'];
        $catalog = $this->terminalCatalogRows()->keyBy(fn (array $row): string => $this->normalizeName($row['nama_provinsi']));
        $rows = [];
        $seen = [];
        $errors = [];

        foreach ($records as $index => $record) {
            $line = $index + 2;
            if (count($record) !== 2) {
                $errors[] = "Baris terminal {$line}: jumlah kolom harus dua.";
                continue;
            }
            [$provinceName, $rawAmount] = $record;
            $sourceKey = $this->normalizeName($provinceName);
            $sourceKey = $sourceAliases[$sourceKey] ?? $sourceKey;
            $catalogRow = $catalog->get($sourceKey);
            if (! $catalogRow) {
                $errors[] = "Baris terminal {$line}: provinsi {$provinceName} tidak ada pada sumber.";
                continue;
            }
            $masterKey = $aliases[$sourceKey] ?? $sourceKey;
            $province = $byName->get($masterKey);
            if (! $province) {
                $errors[] = "Baris terminal {$line}: provinsi {$provinceName} belum ada pada master.";
                continue;
            }
            if (isset($seen[$sourceKey])) {
                $errors[] = "Baris terminal {$line}: provinsi {$provinceName} duplikat.";
                continue;
            }
            $amount = $this->validAmount($rawAmount, "Baris terminal {$line}", $errors);
            if ($amount === null) {
                continue;
            }
            $seen[$sourceKey] = true;
            $rows[] = [
                'source_number' => $catalogRow['source_number'],
                'kode_provinsi' => $province->code,
                'nama_provinsi' => $province->name,
                'amount' => $amount,
            ];
        }
        $this->ensureComplete($catalog, $seen, $rows, self::EXPECTED_TERMINAL_RATES, 'provinsi terminal', $errors, 'terminal_transport_csv');

        return $rows;
    }

    /** @return array<int, array<string, int|string>> */
    public function parseAirfare(UploadedFile $file): array
    {
        $records = $this->readCsv($file, self::AIRFARE_HEADERS, 'airfare_csv', self::EXPECTED_AIRFARE_ROUTES);
        $catalog = $this->airfareCatalogRows()->keyBy(fn (array $row): string => $this->routeKey($row['kota_asal'], $row['kota_tujuan']));
        $rows = [];
        $seen = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $line = $index + 2;
            if (count($record) !== 4) {
                $errors[] = "Baris tiket {$line}: jumlah kolom harus empat.";
                continue;
            }
            [$origin, $destination, $rawBusiness, $rawEconomy] = $record;
            $key = $this->routeKey($origin, $destination);
            $catalogRow = $catalog->get($key);
            if (! $catalogRow) {
                $errors[] = "Baris tiket {$line}: pasangan {$origin} - {$destination} tidak ada pada sumber.";
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = "Baris tiket {$line}: pasangan {$origin} - {$destination} duplikat.";
                continue;
            }
            $business = $this->validAmount($rawBusiness, "Baris tiket {$line} tarif bisnis", $errors);
            $economy = $this->validAmount($rawEconomy, "Baris tiket {$line} tarif ekonomi", $errors);
            if ($business === null || $economy === null) {
                continue;
            }
            if ($economy > $business) {
                $errors[] = "Baris tiket {$line}: tarif ekonomi tidak boleh melebihi bisnis.";
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'source_number' => $catalogRow['source_number'],
                'kota_asal' => $catalogRow['kota_asal'],
                'kota_tujuan' => $catalogRow['kota_tujuan'],
                'origin_key' => $this->normalizeCity($catalogRow['kota_asal']),
                'destination_key' => $this->normalizeCity($catalogRow['kota_tujuan']),
                'business_amount' => $business,
                'economy_amount' => $economy,
            ];
        }
        $this->ensureComplete($catalog, $seen, $rows, self::EXPECTED_AIRFARE_ROUTES, 'pasangan tiket', $errors, 'airfare_csv');

        return $rows;
    }

    /** @return Collection<int, array<string, int|string>> */
    public function terminalCatalogRows(): Collection
    {
        if ($this->terminalCatalog !== null) {
            return $this->terminalCatalog;
        }
        return $this->terminalCatalog = $this->readCatalog(
            resource_path('pmk_terminal_transport_rates.csv'),
            ['source_number', 'nama_provinsi', 'tarif_sumber'],
            self::EXPECTED_TERMINAL_RATES,
            fn (array $row): array => [
                'source_number' => (int) $row[0],
                'nama_provinsi' => trim((string) $row[1]),
                'tarif_sumber' => (int) $row[2],
            ]
        );
    }

    /** @return Collection<int, array<string, int|string>> */
    public function airfareCatalogRows(): Collection
    {
        if ($this->airfareCatalog !== null) {
            return $this->airfareCatalog;
        }
        return $this->airfareCatalog = $this->readCatalog(
            resource_path('pmk_domestic_airfares.csv'),
            ['source_number', 'kota_asal', 'kota_tujuan', 'tarif_bisnis_sumber', 'tarif_ekonomi_sumber'],
            self::EXPECTED_AIRFARE_ROUTES,
            fn (array $row): array => [
                'source_number' => (int) $row[0],
                'kota_asal' => trim((string) $row[1]),
                'kota_tujuan' => trim((string) $row[2]),
                'tarif_bisnis_sumber' => (int) $row[3],
                'tarif_ekonomi_sumber' => (int) $row[4],
            ]
        );
    }

    public function airfareCities(): Collection
    {
        return $this->airfareCatalogRows()
            ->flatMap(fn (array $row): array => [$row['kota_asal'], $row['kota_tujuan']])
            ->unique(fn (string $city): string => $this->normalizeCity($city))
            ->sort()->values();
    }

    public function canonicalAirfareCity(?string $city): ?string
    {
        $key = $this->normalizeCity((string) $city);
        if ($key === '') {
            return null;
        }
        return $this->airfareCities()->first(fn (string $candidate): bool => $this->normalizeCity($candidate) === $key);
    }

    public function findTerminalRate(AirTransportRegulation $regulation, ?int $provinceId): ?TerminalTransportRate
    {
        if (! $provinceId) {
            return null;
        }
        return TerminalTransportRate::query()
            ->where('air_transport_regulation_id', $regulation->id)
            ->where('province_id', $provinceId)
            ->first();
    }

    public function findAirfareRate(
        AirTransportRegulation $regulation,
        string $origin,
        string $destination
    ): ?DomesticAirfareRate {
        $originKey = $this->normalizeCity($origin);
        $destinationKey = $this->normalizeCity($destination);
        if ($originKey === '' || $destinationKey === '' || $originKey === $destinationKey) {
            return null;
        }
        $direct = DomesticAirfareRate::query()
            ->where('air_transport_regulation_id', $regulation->id)
            ->where('origin_key', $originKey)
            ->where('destination_key', $destinationKey)
            ->first();

        return $direct ?: DomesticAirfareRate::query()
            ->where('air_transport_regulation_id', $regulation->id)
            ->where('origin_key', $destinationKey)
            ->where('destination_key', $originKey)
            ->first();
    }

    public function normalizeCity(string $city): string
    {
        return $this->normalizeName($city);
    }

    private function readCsv(UploadedFile $file, array $headers, string $field, int $expected): array
    {
        $content = file_get_contents($file->getRealPath());
        if (! is_string($content) || $content === '' || strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages([$field => 'CSV kosong, terlalu besar, atau bukan UTF-8.']);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = trim((string) strtok($content, "\r\n"));
        $delimiter = match ($firstLine) {
            implode(',', $headers) => ',',
            implode(';', $headers) => ';',
            default => null,
        };
        if ($delimiter === null) {
            throw ValidationException::withMessages([$field => 'Header CSV tidak sesuai template resmi.']);
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $records[] = array_map(fn ($value): string => trim((string) $value), $row);
            if (count($records) > $expected + 1) {
                break;
            }
        }
        fclose($stream);
        if (array_shift($records) !== $headers) {
            throw ValidationException::withMessages([$field => 'Header CSV tidak sesuai template resmi.']);
        }
        return $records;
    }

    private function validAmount(string $raw, string $label, array &$errors): ?int
    {
        if (! preg_match('/^\d+$/', $raw) || (int) $raw <= 0 || (int) $raw > 99_999_999) {
            $errors[] = "{$label}: tarif harus bilangan Rupiah positif tanpa pemisah.";
            return null;
        }
        return (int) $raw;
    }

    private function ensureComplete(Collection $catalog, array $seen, array $rows, int $expected, string $label, array $errors, string $field): void
    {
        $missing = $catalog->keys()->diff(array_keys($seen));
        if ($missing->isNotEmpty()) {
            $errors[] = 'Masih ada '.$missing->count()." {$label} yang belum diisi.";
        }
        if (count($rows) !== $expected) {
            $errors[] = "CSV harus memuat tepat {$expected} {$label} yang valid.";
        }
        if ($errors !== []) {
            throw ValidationException::withMessages([$field => array_slice(array_values(array_unique($errors)), 0, 12)]);
        }
    }

    private function readCatalog(string $path, array $headers, int $expected, callable $mapper): Collection
    {
        $stream = fopen($path, 'rb');
        if ($stream === false || fgetcsv($stream, 0, ',', '"', '\\') !== $headers) {
            throw new RuntimeException('Format katalog transportasi udara tidak valid.');
        }
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = $mapper($row);
            }
        }
        fclose($stream);
        if (count($rows) !== $expected) {
            throw new RuntimeException("Katalog transportasi udara harus memuat tepat {$expected} baris.");
        }
        return collect($rows);
    }

    private function normalizeName(string $name): string
    {
        $upper = mb_strtoupper(trim($name), 'UTF-8');
        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }

    private function routeKey(string $origin, string $destination): string
    {
        return $this->normalizeCity($origin).'|'.$this->normalizeCity($destination);
    }
}
