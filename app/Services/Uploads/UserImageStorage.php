<?php

namespace App\Services\Uploads;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UserImageStorage
{
    public function storeProfilePhoto(UploadedFile $file): string
    {
        $source = $this->decode($file);

        try {
            $source = $this->orientJpeg($source, $file);
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, 1024 / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);

            if (! $target instanceof GdImage) {
                throw new RuntimeException('Gagal menyiapkan foto profil.');
            }

            try {
                $background = imagecolorallocate($target, 255, 255, 255);
                imagefill($target, 0, 0, $background);
                imagecopyresampled(
                    $target,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $width,
                    $height
                );

                $directory = public_path('uploads/profil');
                if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Folder foto profil tidak dapat dibuat.');
                }

                $filename = 'foto_'.Str::uuid().'.jpg';
                if (! imagejpeg($target, $directory.DIRECTORY_SEPARATOR.$filename, 85)) {
                    throw new RuntimeException('Gagal menyimpan foto profil.');
                }

                return $filename;
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }
    }

    public function storeSignature(UploadedFile $file): string
    {
        $source = $this->decode($file);

        try {
            imagesavealpha($source, true);
            ob_start();
            $encoded = imagepng($source, null, 8);
            $png = ob_get_clean();

            if (! $encoded || ! is_string($png)) {
                throw new RuntimeException('Gagal mengodekan tanda tangan.');
            }

            $path = 'signatures/ttd_'.Str::uuid().'.png';
            if (! Storage::disk('local')->put($path, $png)) {
                throw new RuntimeException('Gagal menyimpan tanda tangan.');
            }

            return $path;
        } finally {
            imagedestroy($source);
        }
    }

    public function deleteProfilePhoto(?string $filename): void
    {
        if (
            ! is_string($filename)
            || $filename === ''
            || $filename === 'default.png'
            || preg_match('/\Afoto_[0-9a-f-]+\.jpg\z/i', $filename) !== 1
        ) {
            return;
        }

        $path = public_path('uploads/profil/'.$filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteSignature(?string $path): void
    {
        if (
            is_string($path)
            && preg_match('#\Asignatures/ttd_[0-9a-f-]+\.png\z#i', $path) === 1
        ) {
            Storage::disk('local')->delete($path);
        }
    }

    private function decode(UploadedFile $file): GdImage
    {
        $contents = file_get_contents($file->getRealPath());
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (! $image instanceof GdImage) {
            throw new RuntimeException('File gambar tidak dapat dibaca.');
        }

        return $image;
    }

    private function orientJpeg(GdImage $image, UploadedFile $file): GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = (int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1);
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
            throw new RuntimeException('Orientasi gambar tidak dapat diperbaiki.');
        }

        imagedestroy($image);

        return $rotated;
    }
}
