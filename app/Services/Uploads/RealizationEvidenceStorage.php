<?php

namespace App\Services\Uploads;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RealizationEvidenceStorage
{
    private const DIRECTORY = 'realization-evidence';

    public function store(UploadedFile $file, string $field): array
    {
        $mime = (string) $file->getMimeType();

        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $this->storeImage($file, $field);
        }

        if ($mime === 'application/pdf') {
            return $this->storePdf($file, $field);
        }

        throw ValidationException::withMessages([
            $field => 'Bukti harus berupa JPG, JPEG, PNG, atau PDF yang valid.',
        ]);
    }

    public function deleteMany(array $paths): void
    {
        $managed = array_values(array_filter(
            $paths,
            fn (mixed $path): bool => is_string($path) && $this->isManagedPath($path)
        ));

        if ($managed !== []) {
            Storage::disk('local')->delete($managed);
        }
    }

    public function absolutePath(string $path): ?string
    {
        if (! $this->isManagedPath($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    private function storeImage(UploadedFile $file, string $field): array
    {
        $contents = file_get_contents($file->getRealPath());
        $dimensions = is_string($contents) ? @getimagesizefromstring($contents) : false;

        if (! is_array($dimensions)) {
            throw ValidationException::withMessages([$field => 'Gambar bukti rusak atau tidak dapat dibaca.']);
        }

        [$headerWidth, $headerHeight] = $dimensions;
        if (
            $headerWidth < 100 || $headerHeight < 100
            || $headerWidth > 8000 || $headerHeight > 8000
            || ($headerWidth * $headerHeight) > 40_000_000
        ) {
            throw ValidationException::withMessages([
                $field => 'Dimensi gambar bukti tidak aman atau terlalu kecil untuk dibaca.',
            ]);
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([$field => 'Gambar bukti rusak atau tidak dapat dibaca.']);
        }

        $target = null;

        try {
            $source = $this->applyExifOrientation($source, $file);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if ($sourceWidth < 100 || $sourceHeight < 100 || ($sourceWidth * $sourceHeight) > 40_000_000) {
                throw ValidationException::withMessages([
                    $field => 'Dimensi gambar bukti tidak aman atau terlalu kecil untuk dibaca.',
                ]);
            }

            $maxPixels = max(800, min(3000, (int) config(
                'sim_pd.realization_evidence.normalized_long_edge',
                2000
            )));
            $scale = min(1, $maxPixels / max($sourceWidth, $sourceHeight));
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $target = imagecreatetruecolor($width, $height);

            if (! $target instanceof GdImage) {
                throw new RuntimeException('Gagal menyiapkan gambar bukti realisasi.');
            }

            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);

            if (! imagecopyresampled(
                $target,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight
            )) {
                throw new RuntimeException('Gagal menormalisasi gambar bukti realisasi.');
            }

            ob_start();
            $encoded = imagejpeg($target, null, 88);
            $jpeg = ob_get_clean();

            if (! $encoded || ! is_string($jpeg)) {
                throw new RuntimeException('Gagal mengodekan gambar bukti realisasi.');
            }
        } finally {
            if ($target instanceof GdImage) {
                imagedestroy($target);
            }
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
        }

        $path = self::DIRECTORY.'/'.Str::uuid().'.jpg';

        if (! Storage::disk('local')->put($path, $jpeg)) {
            throw new RuntimeException('Gagal menyimpan gambar bukti realisasi.');
        }

        return $this->metadata($file, $path, 'image/jpeg', strlen($jpeg));
    }

    private function storePdf(UploadedFile $file, string $field): array
    {
        $contents = file_get_contents($file->getRealPath());

        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw ValidationException::withMessages([$field => 'PDF bukti rusak atau tidak valid.']);
        }

        $path = self::DIRECTORY.'/'.Str::uuid().'.pdf';

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Gagal menyimpan PDF bukti realisasi.');
        }

        return $this->metadata($file, $path, 'application/pdf', strlen($contents));
    }

    private function metadata(UploadedFile $file, string $path, string $mime, int $size): array
    {
        $name = trim(basename((string) $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $name) ?? 'bukti';

        return [
            'path' => $path,
            'nama_asli' => Str::limit($name !== '' ? $name : 'bukti', 255, ''),
            'mime_type' => $mime,
            'ukuran' => $size,
        ];
    }

    private function applyExifOrientation(GdImage $image, UploadedFile $file): GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) ((@exif_read_data($file->getRealPath()))['Orientation'] ?? 1);

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0xffffff);

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('Gagal memperbaiki orientasi gambar bukti realisasi.');
        }

        imagedestroy($image);

        return $rotated;
    }

    private function isManagedPath(string $path): bool
    {
        return (bool) preg_match(
            '#\A'.preg_quote(self::DIRECTORY, '#').'/[0-9a-f-]+\.(?:jpg|pdf)\z#D',
            $path
        );
    }
}
