<?php

declare(strict_types=1);

namespace App\Services\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use RuntimeException;

final class LumpsumXlsxGenerator
{
    public function __construct(
        private readonly string $templatePath,
        private readonly string $outputDirectory,
        private readonly array $officials = [],
    ) {}

    public function generate(
        array $data,
        ?string $existingWorkbookPath = null
    ): string {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException(
                'Template Lumpsum tidak ditemukan: '
                    . $this->templatePath
            );
        }

        $this->ensureDirectory(
            $this->outputDirectory
        );

        /*
         * Pada tahap final workbook akan berasal dari
         * template yang sama yang sudah diisi SPPD.
         */
        $sourcePath =
            $existingWorkbookPath
            ?? $this->templatePath;

        $spreadsheet =
            IOFactory::load($sourcePath);

        $sheet =
            $spreadsheet->getSheetByName('Lumpsum');

        if ($sheet === null) {
            throw new RuntimeException(
                'Sheet Lumpsum tidak ditemukan.'
            );
        }

        $this->fillLumpsum(
            $sheet,
            $data
        );

        $this->configurePrint(
            $sheet
        );

        $filename = $this->sanitizeFilename(
            sprintf(
                'Lumpsum_%s_%s_%s.xlsx',
                $data['no_spt'] ?? 'tanpa-nomor',
                $data['nama_lengkap'] ?? 'pegawai',
                date('Ymd_His') . '_' . bin2hex(random_bytes(4))
            )
        );

        $outputPath =
            rtrim(
                $this->outputDirectory,
                DIRECTORY_SEPARATOR
            )
            . DIRECTORY_SEPARATOR
            . $filename;

        $writer =
            IOFactory::createWriter(
                $spreadsheet,
                'Xlsx'
            );

        $writer->setPreCalculateFormulas(false);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    private function fillLumpsum(
        $sheet,
        array $data
    ): void {
        $lamaHari =
            (int) ($data['lama_hari'] ?? 0);

        $uangHarian =
            (float) (
                $data['uang_harian_per_hari']
                ?? 0
            );

        $totalUangHarian =
            (float) (
                $data['total_uang_harian']
                ?? 0
            );

        $transportBandara =
            (float) (
                $data['transport_bandara_dokumen']
                ?? 0
            );

        $hotel =
            (float) (
                $data['biaya_hotel_dokumen']
                ?? 0
            );

        $tiket =
            (float) (
                $data['biaya_tiket_dokumen']
                ?? 0
            );

        $total =
            (float) (
                $data['total_lumpsum']
                ?? 0
            );

        /*
         * =============================================
         * HEADER
         * =============================================
         */

        $sheet->setCellValue(
            'I3',
            (string) (
                $data['no_spt'] ?? ''
            )
        );

        $sheet->setCellValue(
            'I4',
            $this->formatDate(
                $data['tanggal_dokumen']
                    ?? date('Y-m-d')
            )
        );

        /*
         * =============================================
         * RINCIAN BIAYA
         * =============================================
         */

        // 1. Uang Harian
        $sheet->setCellValue(
            'E9',
            $lamaHari
        );

        $sheet->setCellValue(
            'F9',
            'hari'
        );

        $sheet->setCellValue(
            'I9',
            $uangHarian
        );

        $sheet->setCellValue(
            'J9',
            $totalUangHarian
        );

        // 2. Transport Bandara
        $sheet->setCellValue(
            'J10',
            $transportBandara
        );

        // 3. Hotel
        $sheet->setCellValue(
            'J11',
            $hotel
        );

        // 4. Tiket
        $sheet->setCellValue(
            'B12',
            sprintf(
                'Tiket %s - %s PP',
                (string) ($data['tempat_berangkat'] ?? 'Pangkep'),
                (string) ($data['kota_tujuan'] ?? '')
            )
        );

        $sheet->setCellValue(
            'J12',
            $tiket
        );

        /*
         * Sangat penting:
         * jangan gunakan formula SUM dari template.
         * Kita isi TOTAL secara eksplisit dari PHP.
         */
        $sheet->setCellValue(
            'J14',
            $total
        );

        /*
         * Hilangkan formula terbilang dari Excel.
         * Kita isi langsung menggunakan PHP.
         */
        $sheet->setCellValue(
            'C15',
            $this->terbilangRupiah($total)
        );

        /*
         * Tanggal dokumen.
         */
        $sheet->setCellValue(
            'I17',
            'Pangkep, '
                . $this->formatDate(
                    $data['tanggal_dokumen']
                        ?? date('Y-m-d')
                )
        );

        /*
         * NILAI YANG DIBAYAR
         *
         * Template asli menggunakan formula =J14.
         * Kita tulis langsung agar tidak bergantung
         * pada formula Excel.
         */
        $sheet->setCellValue(
            'B20',
            $total
        );

        $sheet->setCellValue(
            'I20',
            $total
        );

        /*
         * =============================================
         * BENDahara
         * =============================================
         */
        $sheet->setCellValue(
            'B27',
            $this->officials['bendahara']['name']
                ?? ''
        );

        $sheet->setCellValue(
            'B28',
            $this->withNipPrefix(
                $this->officials['bendahara']['nip']
                    ?? ''
            )
        );

        /*
         * =============================================
         * PENERIMA
         * =============================================
         */
        $sheet->setCellValue(
            'I27',
            (string) (
                $data['nama_lengkap'] ?? ''
            )
        );

        $sheet->setCellValue(
            'I28',
            $this->withNipPrefix(
                $data['nip'] ?? ''
            )
        );

        /*
         * Hapus blok RAMPUNG dari output.
         *
         * Kita tetap mempertahankan template asli,
         * tetapi sheet hasil tidak akan mencetak area itu.
         */
        // $this->clearRampungValues($sheet);
    }

    // private function clearRampungValues($sheet): void
    // {
    //     /*
    //      * Baris 31-48 adalah blok RAMPUNG pada
    //      * template yang sekarang tidak digunakan.
    //      *
    //      * Kita tidak mengubah merge/style.
    //      * Hanya mengosongkan nilai dinamis agar
    //      * tidak ikut menjadi output jika print area
    //      * berkembang di kemudian hari.
    //      */
    //     for ($row = 31; $row <= 48; $row++) {
    //         for ($column = 1; $column <= 11; $column++) {
    //             $cell = $sheet->getCellByColumnAndRow(
    //                 $column,
    //                 $row
    //             );

    //             /*
    //              * Jangan hapus style.
    //              * Hanya hapus nilai.
    //              */
    //             $cell->setValue(null);
    //         }
    //     }
    // }

    private function configurePrint($sheet): void
    {
        $pageSetup =
            $sheet->getPageSetup();

        $pageSetup->setPrintArea(
            'A1:K29'
        );

        $pageSetup->setPaperSize(
            PageSetup::PAPERSIZE_A4
        );

        $pageSetup->setOrientation(
            PageSetup::ORIENTATION_PORTRAIT
        );

        /*
         * Template asli menggunakan scaling.
         */
        $pageSetup->setFitToPage(false);
        $pageSetup->setFitToWidth(0);
        $pageSetup->setFitToHeight(0);
        $pageSetup->setScale(75);

        $margins =
            $sheet->getPageMargins();

        $margins->setLeft(0.5);
        $margins->setRight(0.5);
        $margins->setTop(0.5);
        $margins->setBottom(0.5);

        $margins->setHeader(0);
        $margins->setFooter(0);
    }

    private function terbilangRupiah(
        float $value
    ): string {
        $value =
            (int) round($value);

        if ($value === 0) {
            return 'Nol Rupiah';
        }

        return ucfirst(
            trim(
                $this->terbilang($value)
            )
        ) . ' Rupiah';
    }

    private function terbilang(
        int $number
    ): string {
        $words = [
            0 => '',
            1 => 'satu',
            2 => 'dua',
            3 => 'tiga',
            4 => 'empat',
            5 => 'lima',
            6 => 'enam',
            7 => 'tujuh',
            8 => 'delapan',
            9 => 'sembilan',
            10 => 'sepuluh',
            11 => 'sebelas',
        ];

        if ($number < 12) {
            return $words[$number];
        }

        if ($number < 20) {
            return $this->terbilang(
                $number - 10
            ) . ' belas';
        }

        if ($number < 100) {
            return $this->terbilang(
                intdiv($number, 10)
            )
                . ' puluh '
                . $this->terbilang(
                    $number % 10
                );
        }

        if ($number < 200) {
            return 'seratus '
                . $this->terbilang(
                    $number - 100
                );
        }

        if ($number < 1000) {
            return $this->terbilang(
                intdiv($number, 100)
            )
                . ' ratus '
                . $this->terbilang(
                    $number % 100
                );
        }

        if ($number < 2000) {
            return 'seribu '
                . $this->terbilang(
                    $number - 1000
                );
        }

        if ($number < 1_000_000) {
            return $this->terbilang(
                intdiv($number, 1000)
            )
                . ' ribu '
                . $this->terbilang(
                    $number % 1000
                );
        }

        if ($number < 1_000_000_000) {
            return $this->terbilang(
                intdiv($number, 1_000_000)
            )
                . ' juta '
                . $this->terbilang(
                    $number % 1_000_000
                );
        }

        if ($number < 1_000_000_000_000) {
            return $this->terbilang(
                intdiv($number, 1_000_000_000)
            )
                . ' miliar '
                . $this->terbilang(
                    $number % 1_000_000_000
                );
        }

        return $this->terbilang(
            intdiv($number, 1_000_000_000_000)
        )
            . ' triliun '
            . $this->terbilang(
                $number % 1_000_000_000_000
            );
    }

    private function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? $date
            : date(
                'd - m - Y',
                $timestamp
            );
    }

    private function withNipPrefix(?string $nip): string
    {
        $nip = trim((string) $nip);

        if ($nip === '') {
            return '';
        }

        return str_starts_with(
            strtoupper($nip),
            'NIP.'
        )
            ? $nip
            : 'NIP. ' . $nip;
    }

    private function sanitizeFilename(
        string $filename
    ): string {
        $filename =
            preg_replace(
                '/[^A-Za-z0-9._-]+/u',
                '_',
                $filename
            ) ?? 'Lumpsum.xlsx';

        return trim(
            $filename,
            '._-'
        ) ?: 'Lumpsum.xlsx';
    }

    private function ensureDirectory(
        string $directory
    ): void {
        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0775,
                true
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Tidak dapat membuat folder output: '
                    . $directory
            );
        }
    }
}
