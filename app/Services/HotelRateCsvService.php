<?php

namespace App\Services;

use App\Models\HotelImport;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class HotelRateCsvService
{
    public const REGULATION_NUMBER = 'PMK Nomor 32 Tahun 2025';
    public const FISCAL_YEAR = 2026;
    public const SOURCE_REFERENCE = 'PMK Nomor 32 Tahun 2025, Lampiran I Tabel 30, halaman 19';
    public const MAX_BYTES = 1_048_576;

    public const HEADERS = ['nama_provinsi', 'tarif_eselon_iv_golongan_iii_ii_i'];

    public const PDF_PROVINCE_ORDER = [
        '11', '12', '14', '21', '15', '13', '16', '18', '17', '19',
        '36', '32', '31', '33', '34', '35', '51', '52', '53', '61',
        '62', '63', '64', '65', '71', '75', '76', '73', '72', '74',
        '81', '82', '94', '91', '92', '96', '95', '97',
    ];

    public function createValidatedImport(UploadedFile $file, User $uploader): HotelImport
    {
        $this->cleanupExpired();
        $rows = $this->parse($file);
        $checksum = hash_file('sha256', $file->getRealPath());

        if (! is_string($checksum)) {
            throw ValidationException::withMessages(['hotel_csv' => 'Checksum file CSV tidak dapat dihitung.']);
        }

        $id = (string) Str::uuid();
        $path = $file->storeAs('pmk/hotel/imports/temporary', $id.'.csv', 'local');
        if (! is_string($path)) {
            throw ValidationException::withMessages(['hotel_csv' => 'File CSV belum dapat disimpan.']);
        }

        try {
            return HotelImport::query()->create([
                'id' => $id,
                'status' => HotelImport::STATUS_VALIDATED,
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

    public function commit(HotelImport $import): HotelRegulation
    {
        if ($import->status !== HotelImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            throw ValidationException::withMessages(['hotel_csv' => 'Pratinjau CSV sudah tidak berlaku.']);
        }

        $temporaryPath = (string) $import->temporary_path;
        if ($temporaryPath === '' || ! Storage::disk('local')->exists($temporaryPath)) {
            throw ValidationException::withMessages(['hotel_csv' => 'File CSV sementara tidak ditemukan.']);
        }
        if (hash_file('sha256', Storage::disk('local')->path($temporaryPath)) !== $import->csv_sha256) {
            throw ValidationException::withMessages(['hotel_csv' => 'File CSV berubah setelah divalidasi.']);
        }

        $archivePath = 'pmk/hotel/imports/archive/'.$import->csv_sha256.'.csv';
        $archiveCreated = false;

        try {
            $regulation = DB::transaction(function () use (
                $import, $temporaryPath, $archivePath, &$archiveCreated
            ): HotelRegulation {
                $lockedImport = HotelImport::query()->lockForUpdate()->findOrFail($import->id);
                if ($lockedImport->status !== HotelImport::STATUS_VALIDATED) {
                    throw ValidationException::withMessages(['hotel_csv' => 'Dataset CSV ini sudah diproses.']);
                }
                if (HotelRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->where('csv_sha256', $lockedImport->csv_sha256)
                    ->exists()) {
                    throw ValidationException::withMessages(['hotel_csv' => 'Dataset CSV yang sama sudah pernah disimpan.']);
                }
                if (! Storage::disk('local')->copy($temporaryPath, $archivePath)) {
                    throw new RuntimeException('Arsip CSV tarif hotel PMK tidak dapat dibuat.');
                }
                $archiveCreated = true;

                $revision = ((int) HotelRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->orderByDesc('revision')->lockForUpdate()->value('revision')) + 1;

                $regulation = HotelRegulation::query()->create([
                    'regulation_number' => self::REGULATION_NUMBER,
                    'fiscal_year' => self::FISCAL_YEAR,
                    'revision' => $revision,
                    'source_reference' => self::SOURCE_REFERENCE,
                    'status' => HotelRegulation::STATUS_DRAFT,
                    'original_filename' => $lockedImport->original_filename,
                    'csv_path' => $archivePath,
                    'csv_sha256' => $lockedImport->csv_sha256,
                    'uploaded_by' => $lockedImport->uploaded_by,
                ]);

                $provinceIds = Province::query()->pluck('id', 'code');
                $now = now();
                HotelRate::query()->insert(collect($lockedImport->parsed_rows)->map(fn (array $row): array => [
                    'hotel_regulation_id' => $regulation->id,
                    'province_id' => $provinceIds[$row['kode_provinsi']],
                    'rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III,
                    'amount' => $row['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $lockedImport->update([
                    'status' => HotelImport::STATUS_COMMITTED,
                    'temporary_path' => null,
                    'hotel_regulation_id' => $regulation->id,
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

    public function discard(HotelImport $import): void
    {
        if ($import->status !== HotelImport::STATUS_VALIDATED) {
            return;
        }
        if ($import->temporary_path) {
            Storage::disk('local')->delete($import->temporary_path);
        }
        $import->update([
            'status' => HotelImport::STATUS_DISCARDED,
            'temporary_path' => null,
            'parsed_rows' => [],
        ]);
    }

    public function cleanupExpired(): void
    {
        HotelImport::query()
            ->where('status', HotelImport::STATUS_VALIDATED)
            ->where('expires_at', '<', now())
            ->orderBy('created_at')
            ->chunkById(50, function ($imports): void {
                foreach ($imports as $import) {
                    if ($import->temporary_path) {
                        Storage::disk('local')->delete($import->temporary_path);
                    }
                    $import->update([
                        'status' => HotelImport::STATUS_EXPIRED,
                        'temporary_path' => null,
                        'parsed_rows' => [],
                    ]);
                }
            }, 'id');
    }

    /** @return array<int, array{kode_provinsi: string, nama_provinsi: string, amount: int}> */
    public function parse(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if (! is_string($content) || $content === '') {
            throw ValidationException::withMessages(['hotel_csv' => 'File CSV kosong atau tidak dapat dibaca.']);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['hotel_csv' => 'Ukuran CSV maksimal 1 MB.']);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['hotel_csv' => 'CSV harus menggunakan encoding UTF-8.']);
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = trim((string) strtok($content, "\r\n"));
        $delimiter = match ($firstLine) {
            implode(',', self::HEADERS) => ',',
            implode(';', self::HEADERS) => ';',
            default => null,
        };
        if ($delimiter === null) {
            throw ValidationException::withMessages(['hotel_csv' => 'Header CSV tidak sesuai template hotel resmi.']);
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
            if (count($records) > 39) {
                break;
            }
        }
        fclose($stream);

        if (array_shift($records) !== self::HEADERS) {
            throw ValidationException::withMessages(['hotel_csv' => 'Header CSV tidak sesuai template hotel resmi.']);
        }

        $provinces = Province::query()->get();
        if ($provinces->count() !== 38) {
            throw new RuntimeException('Master provinsi belum lengkap.');
        }
        $byName = $provinces->keyBy(fn (Province $province): string => $this->normalizeName($province->name));
        $aliases = [
            'BANGKABELITUNG' => 'KEPULAUANBANGKABELITUNG',
        ];

        $rows = [];
        $seen = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $line = $index + 2;
            if (count($record) !== 2) {
                $errors[] = "Baris {$line}: jumlah kolom harus dua.";
                continue;
            }
            [$provinceName, $rawAmount] = $record;
            $normalized = $this->normalizeName($provinceName);
            $normalized = $aliases[$normalized] ?? $normalized;
            $province = $byName->get($normalized);
            if (! $province) {
                $errors[] = "Baris {$line}: provinsi {$provinceName} tidak dikenal.";
                continue;
            }
            if (isset($seen[$province->code])) {
                $errors[] = "Baris {$line}: provinsi {$province->name} duplikat.";
                continue;
            }
            if (! preg_match('/^\d+$/', $rawAmount) || (int) $rawAmount <= 0 || (int) $rawAmount > 99_999_999) {
                $errors[] = "Baris {$line}: tarif harus bilangan Rupiah positif tanpa pemisah.";
                continue;
            }

            $seen[$province->code] = true;
            $rows[] = [
                'kode_provinsi' => $province->code,
                'nama_provinsi' => $province->name,
                'amount' => (int) $rawAmount,
            ];
        }

        $missing = $provinces->pluck('code')->diff(array_keys($seen))->values();
        if ($missing->isNotEmpty()) {
            $errors[] = 'Provinsi belum lengkap: '.$missing->implode(', ').'.';
        }
        if (count($rows) !== 38) {
            $errors[] = 'CSV harus memuat tepat 38 provinsi yang valid.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages([
                'hotel_csv' => array_slice(array_values(array_unique($errors)), 0, 12),
            ]);
        }

        return $rows;
    }

    private function normalizeName(string $name): string
    {
        $upper = mb_strtoupper(trim($name), 'UTF-8');

        return preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    }
}
