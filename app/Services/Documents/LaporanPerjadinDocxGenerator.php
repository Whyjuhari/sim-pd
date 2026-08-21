<?php

namespace App\Services\Documents;

use Carbon\Carbon;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use GdImage;
use RuntimeException;

class LaporanPerjadinDocxGenerator
{
    private const DOCUMENTATION_PHOTO_PIXELS = 1200;

    private const DOCUMENTATION_CANVAS_WIDTH_PIXELS = 1800;

    private const DOCUMENTATION_GAP_PIXELS = 60;

    private const DOCUMENTATION_WHITE_THRESHOLD = 248;

    private const DOCUMENTATION_WHITE_LINE_RATIO = 0.995;

    private const DOCUMENTATION_MIN_PADDING_PIXELS = 12;

    private const DOCUMENTATION_PADDING_SAFETY_PIXELS = 4;

    public function __construct(
        private readonly string $templatePath,
        private readonly string $outputDirectory,
    ) {}


    public function generate(array $data): string
    {

        if (! is_file($this->templatePath)) {
            throw new RuntimeException(
                'Template Laporan Perjadin tidak ditemukan: '
                    . $this->templatePath
            );
        }

        if (
            ! is_dir($this->outputDirectory)
            && ! mkdir(
                $this->outputDirectory,
                0775,
                true
            )
            && ! is_dir($this->outputDirectory)
        ) {
            throw new RuntimeException(
                'Folder sementara dokumen tidak dapat dibuat.'
            );
        }

        $signaturePath =
            $data['ttd_absolute_path']
            ?? null;

        if (
            ! $signaturePath
            || ! is_file($signaturePath)
        ) {
            throw new RuntimeException(
                'File tanda tangan pegawai belum tersedia.'
            );
        }

        Settings::setOutputEscapingEnabled(true);

        $template =
            new TemplateProcessor(
                $this->templatePath
            );

        $template->setValues([
            'nama_pegawai'
            => $this->value(
                $data['nama_lengkap'] ?? null
            ),

            'nip_pegawai'
            => $this->value(
                $data['nip'] ?? null
            ),

            'jabatan_pegawai'
            => $this->value(
                $data['jabatan'] ?? null
            ),

            'pangkat_golongan'
            => $this->value(
                $data['pangkat_golongan'] ?? null
            ),

            'nomor_spt'
            => $this->value(
                $data['no_spt'] ?? null
            ),

            'jadwal_kegiatan'
            => $this->formatDateRange(
                $data['tgl_berangkat'] ?? null,
                $data['tgl_kembali'] ?? null
            ),

            'tempat_kegiatan'
            => $this->value(
                $data['kota_tujuan'] ?? null
            ),

            'maksud_kegiatan'
            => $this->value(
                $data['maksud_perjalanan'] ?? null
            ),

            'akun_dipa'
            => $this->value(
                $data['akun_anggaran'] ?? null
            ),

            'hasil_pelaksanaan'
            => $this->reportText(
                $data['hasil_pelaksanaan'] ?? null
            ),

            'kesimpulan'
            => $this->reportText(
                $data['kesimpulan'] ?? null
            ),

            'tanggal_laporan'
            => $this->formatDate(
                $data['tanggal_laporan'] ?? null
            ),
        ]);

        $montagePath = null;

        try {
            $documentationPaths = array_values(
                array_filter(
                    (array) ($data['foto_dokumentasi_paths'] ?? []),
                    fn (mixed $path): bool =>
                    is_string($path)
                        && is_file($path)
                )
            );
            $documentationPaths = array_slice(
                $documentationPaths,
                0,
                2
            );

            if ($documentationPaths === []) {
                $template->setValue(
                    'foto_dokumentasi',
                    'Dokumentasi tidak tersedia pada laporan lama.'
                );
            } else {
                $montagePath =
                    $this->createDocumentationMontage(
                        $documentationPaths
                    );
                $photoSizeCm = max(
                    1,
                    min(
                        10,
                        (int) config(
                            'sim_pd.documents.report_documentation.display_size_cm',
                            10
                        )
                    )
                );
                $heightCm = ($photoSizeCm * count($documentationPaths))
                    + (0.5 * (count($documentationPaths) - 1));

                $template->setImageValue(
                    'foto_dokumentasi',
                    [
                        'path' => $montagePath,
                        'width' => ($photoSizeCm * 1.5) . 'cm',
                        'height' => $heightCm . 'cm',
                        'ratio' => 'false',
                    ]
                );
            }

            $template->setImageValue(
                'sign',
                [
                    'path'
                    => $signaturePath,

                    'width'
                    => '4cm',

                    'height'
                    => '1.8cm',

                    'ratio'
                    => 'true',
                ]
            );


            $safeNumber =
                $this->sanitize(
                    $data['no_spt']
                        ?? 'tanpa-nomor'
                );

            $safeName =
                $this->sanitize(
                    $data['nama_lengkap']
                        ?? 'pegawai'
                );


            $filename =
                'Laporan_Perjadin_'
                . $safeNumber
                . '_'
                . $safeName
                . '_'
                . date('Ymd_His')
                . '_'
                . bin2hex(random_bytes(4))
                . '.docx';


            $path =
                rtrim(
                    $this->outputDirectory,
                    DIRECTORY_SEPARATOR
                )
                . DIRECTORY_SEPARATOR
                . $filename;


            $template->saveAs(
                $path
            );


            return $path;
        } finally {
            if (
                $montagePath
                && is_file($montagePath)
            ) {
                @unlink($montagePath);
            }
        }
    }

    private function createDocumentationMontage(
        array $paths
    ): string {
        $canvasHeight = (
            self::DOCUMENTATION_PHOTO_PIXELS * count($paths)
        ) + (
            self::DOCUMENTATION_GAP_PIXELS * (count($paths) - 1)
        );
        $canvas = imagecreatetruecolor(
            self::DOCUMENTATION_CANVAS_WIDTH_PIXELS,
            $canvasHeight
        );

        if (! $canvas instanceof GdImage) {
            throw new RuntimeException(
                'Gagal menyiapkan bidang foto dokumentasi.'
            );
        }

        $path = rtrim(
            $this->outputDirectory,
            DIRECTORY_SEPARATOR
        )
            . DIRECTORY_SEPARATOR
            . 'documentation_montage_'
            . bin2hex(random_bytes(8))
            . '.jpg';

        try {
            $white = imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );
            imagefill($canvas, 0, 0, $white);

            $targetY = 0;

            foreach ($paths as $sourcePath) {
                $contents = file_get_contents($sourcePath);
                $source = $contents !== false
                    ? @imagecreatefromstring($contents)
                    : false;

                if (! $source instanceof GdImage) {
                    throw new RuntimeException(
                        'Salah satu foto dokumentasi tidak dapat dibaca.'
                    );
                }

                try {
                    $bounds = $this->documentationContentBounds(
                        $source
                    );
                    $scale = min(
                        self::DOCUMENTATION_PHOTO_PIXELS
                            / $bounds['width'],
                        self::DOCUMENTATION_PHOTO_PIXELS
                            / $bounds['height']
                    );
                    $targetWidth = max(
                        1,
                        (int) round($bounds['width'] * $scale)
                    );
                    $targetHeight = max(
                        1,
                        (int) round($bounds['height'] * $scale)
                    );
                    $targetX = (int) floor(
                        (
                            self::DOCUMENTATION_CANVAS_WIDTH_PIXELS
                            - $targetWidth
                        ) / 2
                    );

                    if (! imagecopyresampled(
                        $canvas,
                        $source,
                        $targetX,
                        $targetY,
                        $bounds['x'],
                        $bounds['y'],
                        $targetWidth,
                        $targetHeight,
                        $bounds['width'],
                        $bounds['height']
                    )) {
                        throw new RuntimeException(
                            'Gagal menyusun foto dokumentasi.'
                        );
                    }

                    $targetY += $targetHeight
                        + self::DOCUMENTATION_GAP_PIXELS;
                } finally {
                    imagedestroy($source);
                }
            }

            if (! imagejpeg($canvas, $path, 90)) {
                throw new RuntimeException(
                    'Gagal membuat gambar dokumentasi laporan.'
                );
            }
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw $exception;
        } finally {
            imagedestroy($canvas);
        }

        return $path;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function documentationContentBounds(
        GdImage $image
    ): array {
        $width = imagesx($image);
        $height = imagesy($image);
        $top = $this->nearWhiteEdgeLines(
            $image,
            'rows',
            true
        );
        $bottom = $this->nearWhiteEdgeLines(
            $image,
            'rows',
            false
        );
        $left = $this->nearWhiteEdgeLines(
            $image,
            'columns',
            true
        );
        $right = $this->nearWhiteEdgeLines(
            $image,
            'columns',
            false
        );
        $hasHorizontalPadding = $this->isSymmetricPaddingPair(
            $top,
            $bottom,
            $height
        );
        $hasVerticalPadding = $this->isSymmetricPaddingPair(
            $left,
            $right,
            $width
        );

        if ($hasHorizontalPadding && $hasVerticalPadding) {
            $horizontalRatio = ($top + $bottom) / $height;
            $verticalRatio = ($left + $right) / $width;

            if (abs($horizontalRatio - $verticalRatio) < 0.02) {
                $hasHorizontalPadding = false;
                $hasVerticalPadding = false;
            } elseif ($horizontalRatio > $verticalRatio) {
                $hasVerticalPadding = false;
            } else {
                $hasHorizontalPadding = false;
            }
        }

        if ($hasHorizontalPadding) {
            $trimTop = max(
                0,
                $top - self::DOCUMENTATION_PADDING_SAFETY_PIXELS
            );
            $trimBottom = max(
                0,
                $bottom - self::DOCUMENTATION_PADDING_SAFETY_PIXELS
            );

            return [
                'x' => 0,
                'y' => $trimTop,
                'width' => $width,
                'height' => $height - $trimTop - $trimBottom,
            ];
        }

        if ($hasVerticalPadding) {
            $trimLeft = max(
                0,
                $left - self::DOCUMENTATION_PADDING_SAFETY_PIXELS
            );
            $trimRight = max(
                0,
                $right - self::DOCUMENTATION_PADDING_SAFETY_PIXELS
            );

            return [
                'x' => $trimLeft,
                'y' => 0,
                'width' => $width - $trimLeft - $trimRight,
                'height' => $height,
            ];
        }

        return [
            'x' => 0,
            'y' => 0,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function nearWhiteEdgeLines(
        GdImage $image,
        string $axis,
        bool $fromStart
    ): int {
        $width = imagesx($image);
        $height = imagesy($image);
        $lineCount = $axis === 'rows' ? $height : $width;
        $pixelsPerLine = $axis === 'rows' ? $width : $height;
        $minimumWhitePixels = (int) ceil(
            $pixelsPerLine * self::DOCUMENTATION_WHITE_LINE_RATIO
        );
        $trueColor = imageistruecolor($image);

        for ($offset = 0; $offset < $lineCount; $offset++) {
            $line = $fromStart
                ? $offset
                : $lineCount - 1 - $offset;
            $whitePixels = 0;

            for ($pixel = 0; $pixel < $pixelsPerLine; $pixel++) {
                $x = $axis === 'rows' ? $pixel : $line;
                $y = $axis === 'rows' ? $line : $pixel;
                $color = imagecolorat($image, $x, $y);

                if ($trueColor) {
                    $red = ($color >> 16) & 0xFF;
                    $green = ($color >> 8) & 0xFF;
                    $blue = $color & 0xFF;
                } else {
                    $channels = imagecolorsforindex($image, $color);
                    $red = $channels['red'];
                    $green = $channels['green'];
                    $blue = $channels['blue'];
                }

                if (
                    $red >= self::DOCUMENTATION_WHITE_THRESHOLD
                    && $green >= self::DOCUMENTATION_WHITE_THRESHOLD
                    && $blue >= self::DOCUMENTATION_WHITE_THRESHOLD
                ) {
                    $whitePixels++;
                }
            }

            if ($whitePixels < $minimumWhitePixels) {
                return $offset;
            }
        }

        return $lineCount;
    }

    private function isSymmetricPaddingPair(
        int $first,
        int $second,
        int $dimension
    ): bool {
        if (
            min($first, $second)
                < self::DOCUMENTATION_MIN_PADDING_PIXELS
            || ($first + $second) >= $dimension
        ) {
            return false;
        }

        $tolerance = max(
            self::DOCUMENTATION_MIN_PADDING_PIXELS,
            (int) round(max($first, $second) * 0.1)
        );

        return abs($first - $second) <= $tolerance;
    }

    private function value(
        mixed $value
    ): string {
        $value =
            trim(
                (string) ($value ?? '')
            );

        return $value !== ''
            ? $value
            : '-';
    }

    private function reportText(
        mixed $value
    ): string {
        $text =
            trim(
                (string) ($value ?? '')
            );

        if ($text === '') {
            return '-';
        }

        return str_replace(
            ["\r\n", "\r"],
            "\n",
            $text
        );
    }


    private function formatDateRange(
        mixed $start,
        mixed $end
    ): string {
        if (! $start || ! $end) {
            return '-';
        }

        return
            $this->formatDate($start)
            . ' s.d. '
            . $this->formatDate($end);
    }

    private function formatDate(
        mixed $date
    ): string {
        if (! $date) {
            return '-';
        }

        return Carbon::parse($date)
            ->locale('id')
            ->translatedFormat(
                'd F Y'
            );
    }

    private function sanitize(
        string $value
    ): string {
        return trim(
            preg_replace(
                '/[^A-Za-z0-9._-]+/u',
                '_',
                $value
            ) ?: 'dokumen',
            '._-'
        );
    }
}
