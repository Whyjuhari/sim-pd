<?php

namespace App\Services;

use App\Models\DailyAllowanceImport;
use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\Province;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DailyAllowanceCsvService
{
    public const REGULATION_NUMBER = 'PMK Nomor 32 Tahun 2025';
    public const FISCAL_YEAR = 2026;
    public const SOURCE_REFERENCE = 'PMK Nomor 32 Tahun 2025, Lampiran Tabel 28.1, halaman 15';
    public const MAX_BYTES = 1_048_576;

    public const HEADERS = [
        'kode_provinsi',
        'nama_provinsi',
        'luar_kota',
        'dalam_kota_lebih_8_jam',
        'diklat',
    ];

    /** Urutan provinsi pada Lampiran Tabel 28.1 PMK Nomor 32 Tahun 2025. */
    public const PDF_PROVINCE_ORDER = [
        '11', '12', '14', '21', '15', '13', '16', '18', '17', '19',
        '36', '32', '31', '33', '34', '35', '51', '52', '53', '61',
        '62', '63', '64', '65', '71', '75', '76', '73', '72', '74',
        '81', '82', '94', '91', '92', '96', '95', '97',
    ];

    public function createValidatedImport(UploadedFile $file, User $uploader): DailyAllowanceImport
    {
        $this->cleanupExpired();
        $rows = $this->parse($file);
        $checksum = hash_file('sha256', $file->getRealPath());

        if (! is_string($checksum)) {
            throw ValidationException::withMessages([
                'csv' => 'Checksum file CSV tidak dapat dihitung.',
            ]);
        }

        $id = (string) Str::uuid();
        $storedName = $id . '.csv';
        $path = $file->storeAs('pmk/daily-allowance/imports/temporary', $storedName, 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'csv' => 'File CSV belum dapat disimpan. Silakan coba lagi.',
            ]);
        }

        try {
            return DailyAllowanceImport::query()->create([
                'id' => $id,
                'status' => DailyAllowanceImport::STATUS_VALIDATED,
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

    public function commit(DailyAllowanceImport $import): DailyAllowanceRegulation
    {
        if ($import->status !== DailyAllowanceImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'csv' => 'Pratinjau CSV sudah tidak berlaku. Unggah kembali file tarif.',
            ]);
        }

        $temporaryPath = (string) $import->temporary_path;
        if ($temporaryPath === '' || ! Storage::disk('local')->exists($temporaryPath)) {
            throw ValidationException::withMessages([
                'csv' => 'File CSV sementara tidak ditemukan. Unggah kembali file tarif.',
            ]);
        }

        if (hash_file('sha256', Storage::disk('local')->path($temporaryPath)) !== $import->csv_sha256) {
            throw ValidationException::withMessages([
                'csv' => 'File CSV berubah setelah divalidasi. Proses dibatalkan.',
            ]);
        }

        $archivePath = 'pmk/daily-allowance/imports/archive/' . $import->csv_sha256 . '.csv';
        $archiveCreated = false;

        try {
            $regulation = DB::transaction(function () use (
                $import,
                $archivePath,
                $temporaryPath,
                &$archiveCreated
            ): DailyAllowanceRegulation {
                $lockedImport = DailyAllowanceImport::query()->lockForUpdate()->findOrFail($import->id);
                if ($lockedImport->status !== DailyAllowanceImport::STATUS_VALIDATED) {
                    throw ValidationException::withMessages([
                        'csv' => 'Dataset CSV ini sudah diproses.',
                    ]);
                }

                if (DailyAllowanceRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->where('csv_sha256', $lockedImport->csv_sha256)
                    ->exists()
                ) {
                    throw ValidationException::withMessages([
                        'csv' => 'Dataset CSV yang sama sudah pernah disimpan.',
                    ]);
                }

                if (! Storage::disk('local')->copy($temporaryPath, $archivePath)) {
                    throw new RuntimeException('Arsip CSV tarif PMK tidak dapat dibuat.');
                }
                $archiveCreated = true;

                $latestRevision = DailyAllowanceRegulation::query()
                    ->where('regulation_number', self::REGULATION_NUMBER)
                    ->where('fiscal_year', self::FISCAL_YEAR)
                    ->orderByDesc('revision')
                    ->lockForUpdate()
                    ->value('revision');
                $revision = ((int) $latestRevision) + 1;

                $regulation = DailyAllowanceRegulation::query()->create([
                    'regulation_number' => self::REGULATION_NUMBER,
                    'fiscal_year' => self::FISCAL_YEAR,
                    'revision' => $revision,
                    'source_reference' => self::SOURCE_REFERENCE,
                    'status' => DailyAllowanceRegulation::STATUS_DRAFT,
                    'original_filename' => $lockedImport->original_filename,
                    'csv_path' => $archivePath,
                    'csv_sha256' => $lockedImport->csv_sha256,
                    'uploaded_by' => $lockedImport->uploaded_by,
                ]);

                $provinceIds = Province::query()->pluck('id', 'code');
                $now = now();
                $rates = collect($lockedImport->parsed_rows)->map(fn(array $row): array => [
                    'daily_allowance_regulation_id' => $regulation->id,
                    'province_id' => $provinceIds[$row['kode_provinsi']],
                    'outside_city' => $row[DailyAllowanceRate::CATEGORY_OUTSIDE_CITY],
                    'inside_city_over_8_hours' => $row[DailyAllowanceRate::CATEGORY_INSIDE_CITY_OVER_8_HOURS],
                    'training' => $row[DailyAllowanceRate::CATEGORY_TRAINING],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                DailyAllowanceRate::query()->insert($rates);

                $lockedImport->update([
                    'status' => DailyAllowanceImport::STATUS_COMMITTED,
                    'temporary_path' => null,
                    'daily_allowance_regulation_id' => $regulation->id,
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

    public function discard(DailyAllowanceImport $import): void
    {
        if ($import->status !== DailyAllowanceImport::STATUS_VALIDATED) {
            return;
        }

        if ($import->temporary_path) {
            Storage::disk('local')->delete($import->temporary_path);
        }

        $import->update([
            'status' => DailyAllowanceImport::STATUS_DISCARDED,
            'temporary_path' => null,
            'parsed_rows' => [],
        ]);
    }

    public function cleanupExpired(): void
    {
        DailyAllowanceImport::query()
            ->where('status', DailyAllowanceImport::STATUS_VALIDATED)
            ->where('expires_at', '<', now())
            ->orderBy('created_at')
            ->chunkById(50, function ($imports): void {
                foreach ($imports as $import) {
                    if ($import->temporary_path) {
                        Storage::disk('local')->delete($import->temporary_path);
                    }
                    $import->update([
                        'status' => DailyAllowanceImport::STATUS_EXPIRED,
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
            throw ValidationException::withMessages(['csv' => 'File CSV kosong atau tidak dapat dibaca.']);
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['csv' => 'Ukuran CSV maksimal 1 MB.']);
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['csv' => 'CSV harus menggunakan encoding UTF-8.']);
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
                'csv' => 'Header CSV tidak sesuai template resmi. Gunakan file template dari sistem.',
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
            $records[] = array_map(fn($value): string => trim((string) $value), $row);
            if (count($records) > 39) {
                break;
            }
        }
        fclose($stream);

        $header = array_shift($records);
        if ($header !== self::HEADERS) {
            throw ValidationException::withMessages([
                'csv' => 'Header CSV tidak sesuai template resmi. Gunakan file template dari sistem.',
            ]);
        }

        $provinces = Province::query()->get()->keyBy('code');
        if ($provinces->count() !== 38) {
            throw new RuntimeException('Master provinsi belum lengkap.');
        }

        $rows = [];
        $seenCodes = [];
        $errors = [];
        foreach ($records as $index => $record) {
            $line = $index + 2;
            if (count($record) !== count(self::HEADERS)) {
                $errors[] = "Baris {$line}: jumlah kolom harus lima.";
                continue;
            }

            $row = array_combine(self::HEADERS, $record);
            $code = str_pad((string) $row['kode_provinsi'], 2, '0', STR_PAD_LEFT);
            $province = $provinces->get($code);
            if (! $province) {
                $errors[] = "Baris {$line}: kode provinsi {$code} tidak dikenal.";
                continue;
            }
            if (isset($seenCodes[$code])) {
                $errors[] = "Baris {$line}: kode provinsi {$code} duplikat.";
                continue;
            }
            if ($this->normalizeName($row['nama_provinsi']) !== $this->normalizeName($province->name)) {
                $errors[] = "Baris {$line}: nama provinsi tidak sesuai dengan kode {$code}.";
                continue;
            }

            $amounts = [];
            $amountFields = [
                'luar_kota' => DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
                'dalam_kota_lebih_8_jam' => DailyAllowanceRate::CATEGORY_INSIDE_CITY_OVER_8_HOURS,
                'diklat' => DailyAllowanceRate::CATEGORY_TRAINING,
            ];
            foreach ($amountFields as $csvField => $internalField) {
                $value = (string) $row[$csvField];
                if (! preg_match('/^\d+$/', $value) || (int) $value > 99_999_999) {
                    $errors[] = "Baris {$line}: {$csvField} harus bilangan Rupiah utuh tanpa pemisah.";
                    continue 2;
                }
                $amounts[$internalField] = (int) $value;
            }

            $seenCodes[$code] = true;
            $rows[] = [
                'kode_provinsi' => $code,
                'nama_provinsi' => $province->name,
                ...$amounts,
            ];
        }

        $missing = $provinces->keys()->diff(array_keys($seenCodes))->values();
        if ($missing->isNotEmpty()) {
            $errors[] = 'Provinsi belum lengkap: ' . $missing->implode(', ') . '.';
        }
        if (count($rows) !== 38) {
            $errors[] = 'CSV harus memuat tepat 38 provinsi yang valid.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'csv' => array_slice(array_values(array_unique($errors)), 0, 12),
            ]);
        }

        return $rows;
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim($name), 'UTF-8')) ?? '';
    }
}
