<?php

namespace App\Services\Uploads;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReportDocumentationImageStorage
{
    private const DIRECTORY = 'report-documentation';

    public function store(UploadedFile $file): string
    {
        $contents = file_get_contents(
            $file->getRealPath()
        );

        if ($contents === false) {
            throw $this->invalidImageException();
        }

        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw $this->invalidImageException();
        }

        $canvas = null;

        try {
            $source = $this->applyExifOrientation(
                $source,
                $file
            );

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $pixels = max(
                600,
                min(
                    2400,
                    (int) config(
                        'sim_pd.documents.report_documentation.normalized_pixels',
                        1200
                    )
                )
            );

            $canvas = imagecreatetruecolor(
                $pixels,
                $pixels
            );

            if (! $canvas instanceof GdImage) {
                throw new RuntimeException(
                    'Gagal menyiapkan gambar dokumentasi.'
                );
            }

            $white = imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );
            imagefill($canvas, 0, 0, $white);

            $scale = min(
                $pixels / $sourceWidth,
                $pixels / $sourceHeight
            );
            $targetWidth = max(
                1,
                (int) round($sourceWidth * $scale)
            );
            $targetHeight = max(
                1,
                (int) round($sourceHeight * $scale)
            );
            $targetX = (int) floor(
                ($pixels - $targetWidth) / 2
            );
            $targetY = (int) floor(
                ($pixels - $targetHeight) / 2
            );

            if (! imagecopyresampled(
                $canvas,
                $source,
                $targetX,
                $targetY,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight
            )) {
                throw new RuntimeException(
                    'Gagal menormalisasi gambar dokumentasi.'
                );
            }

            ob_start();
            $encoded = imagejpeg(
                $canvas,
                null,
                max(
                    60,
                    min(
                        95,
                        (int) config(
                            'sim_pd.documents.report_documentation.jpeg_quality',
                            85
                        )
                    )
                )
            );
            $jpeg = ob_get_clean();

            if (! $encoded || ! is_string($jpeg)) {
                throw new RuntimeException(
                    'Gagal mengodekan gambar dokumentasi.'
                );
            }
        } finally {
            if ($canvas instanceof GdImage) {
                imagedestroy($canvas);
            }

            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
        }

        $path = self::DIRECTORY
            . '/'
            . Str::uuid()
            . '.jpg';

        if (! Storage::disk('local')->put($path, $jpeg)) {
            throw new RuntimeException(
                'Gagal menyimpan gambar dokumentasi.'
            );
        }

        return $path;
    }

    public function deleteMany(array $paths): void
    {
        $managedPaths = array_values(
            array_filter(
                $paths,
                fn (mixed $path): bool =>
                is_string($path)
                    && $this->isManagedPath($path)
            )
        );

        if ($managedPaths !== []) {
            Storage::disk('local')->delete(
                $managedPaths
            );
        }
    }

    private function applyExifOrientation(
        GdImage $image,
        UploadedFile $file
    ): GdImage {
        if (
            $file->getMimeType() !== 'image/jpeg'
            || ! function_exists('exif_read_data')
        ) {
            return $image;
        }

        $exif = @exif_read_data(
            $file->getRealPath()
        );
        $orientation = (int) ($exif['Orientation'] ?? 1);

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

        $rotated = imagerotate(
            $image,
            $angle,
            0xffffff
        );

        if (! $rotated instanceof GdImage) {
            throw new RuntimeException(
                'Gagal memperbaiki orientasi gambar dokumentasi.'
            );
        }

        imagedestroy($image);

        return $rotated;
    }

    private function isManagedPath(string $path): bool
    {
        return (bool) preg_match(
            '#\A'
                . preg_quote(self::DIRECTORY, '#')
                . '/[0-9a-f-]+\.jpg\z#D',
            $path
        );
    }

    private function invalidImageException(): ValidationException
    {
        return ValidationException::withMessages([
            'foto_dokumentasi' =>
            'Salah satu foto dokumentasi rusak atau tidak dapat dibaca.',
        ]);
    }
}
