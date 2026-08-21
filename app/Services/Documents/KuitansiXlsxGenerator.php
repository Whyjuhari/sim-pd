<?php

declare(strict_types=1);

namespace App\Services\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use RuntimeException;

final class KuitansiXlsxGenerator
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
                'Template Kuitansi tidak ditemukan: '
                    . $this->templatePath
            );
        }

        $this->ensureDirectory(
            $this->outputDirectory
        );

        /*
         * Pada tahap final nanti workbook ini akan berasal
         * dari SPPD + Lumpsum yang sudah diproses.
         *
         * Untuk pengujian awal, generator juga dapat
         * bekerja dari template asli.
         */
        $sourcePath =
            $existingWorkbookPath
            ?? $this->templatePath;

        $spreadsheet =
            IOFactory::load($sourcePath);

        $sheet =
            $spreadsheet->getSheetByName('Kuitansi');

        if ($sheet === null) {
            throw new RuntimeException(
                'Sheet Kuitansi tidak ditemukan.'
            );
        }

        $this->fillKuitansi(
            $sheet,
            $data
        );

        $this->configurePrint(
            $sheet
        );

        $filename = sprintf(
            'Kuitansi_%s_%s_%s.xlsx',
            $data['no_spt'] ?? 'tanpa-nomor',
            $data['nama_lengkap'] ?? 'pegawai',
            date('Ymd_His') . '_' . bin2hex(random_bytes(4))
        );

        $filename =
            $this->sanitizeFilename(
                $filename
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

        $writer->setPreCalculateFormulas(
            false
        );

        $writer->save(
            $outputPath
        );

        $spreadsheet->disconnectWorksheets();

        unset($spreadsheet);

        return $outputPath;
    }

    private function fillKuitansi(
        $sheet,
        array $data
    ): void {
        /*
         * ===============================
         * HEADER
         * ===============================
         */

        $sheet->setCellValue(
            'C1',
            $this->officials['kementerian']
                ?? 'KEMENTERIAN KETENAGAKERJAAN RI'
        );

        /*
         * Beban MAK / akun.
         */
        $sheet->setCellValue(
            'F4',
            (string) (
                $data['akun_anggaran'] ?? ''
            )
        );

        /*
         * Tahun anggaran.
         */
        $sheet->setCellValue(
            'F5',
            $this->getTahunAnggaran(
                $data
            )
        );

        /*
         * ===============================
         * NILAI KUITANSI
         * ===============================
         */

        $total =
            (float) (
                $data['total_lumpsum']
                ?? 0
            );

        /*
         * Uang sebesar
         */
        $sheet->setCellValue(
            'C13',
            $total
        );

        /*
         * Terbilang
         */
        $sheet->setCellValue(
            'C15',
            $this->terbilangRupiah(
                $total
            )
        );

        /*
         * ===============================
         * KETERANGAN PERJALANAN
         * ===============================
         */

        $sheet->setCellValue(
            'C18',
            (string) (
                $data['maksud_perjalanan']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'C22',
            (string) (
                $data['no_spt']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'C24',
            $this->formatDateForDocument(
                $data['tanggal_dokumen']
                    ?? date('Y-m-d')
            )
        );

        $sheet->setCellValue(
            'C26',
            (string) (
                $data['tempat_berangkat']
                ?? 'Pangkep'
            )
        );

        $sheet->setCellValue(
            'F26',
            (string) (
                $data['kota_tujuan']
                ?? ''
            )
        );

        /*
         * ===============================
         * PENERIMA
         * ===============================
         */

        $sheet->setCellValue(
            'F34',
            (string) (
                $data['nama_lengkap']
                ?? ''
            )
        );

        $sheet->setCellValue(
            'F35',
            $this->withNipPrefix(
                $data['nip'] ?? ''
            )
        );

        /*
         * ===============================
         * PEJABAT
         * ===============================
         */

        $ppkName =
            $this->officials['ppk']['name']
            ?? '';

        $ppkNip =
            $this->officials['ppk']['nip']
            ?? '';

        $sheet->setCellValue(
            'A45',
            $ppkName
        );

        $sheet->setCellValue(
            'A46',
            $this->withNipPrefix(
                $ppkNip
            )
        );

        /*
         * Bendahara Pengeluaran.
         */
        $bendaharaName =
            $this->officials['bendahara']['name']
            ?? '';

        $bendaharaNip =
            $this->officials['bendahara']['nip']
            ?? '';

        /*
         * Template asli menempatkan nama/NIP
         * bendahara pada F45/F46.
         */
        $sheet->setCellValue(
            'F45',
            $bendaharaName
        );

        $sheet->setCellValue(
            'F46',
            $this->withNipPrefix(
                $bendaharaNip
            )
        );
    }

    private function configurePrint(
        $sheet
    ): void {
        $pageSetup =
            $sheet->getPageSetup();

        /*
         * Kita hanya menggunakan bagian pertama
         * Kuitansi, baris 1-46.
         *
         * Baris 47 dan seterusnya merupakan blok
         * kedua yang tidak digunakan karena RAMPUNG
         * tidak menjadi dokumen output.
         */
        $pageSetup->setPrintArea(
            'A1:H46'
        );

        /*
         * Ikuti template asli.
         */
        $pageSetup->setPaperSize(
            PageSetup::PAPERSIZE_A4
        );

        $pageSetup->setOrientation(
            PageSetup::ORIENTATION_PORTRAIT
        );

        /*
         * Template Kuitansi menggunakan scaling
         * sekitar 95%.
         */
        $pageSetup->setFitToPage(
            false
        );

        $pageSetup->setFitToWidth(0);
        $pageSetup->setFitToHeight(0);

        $pageSetup->setScale(95);

        /*
         * Pertahankan margin yang aman untuk cetak.
         */
        $margins =
            $sheet->getPageMargins();

        $margins->setLeft(0.5);
        $margins->setRight(0.5);
        $margins->setTop(0.5);
        $margins->setBottom(0.5);

        $margins->setHeader(0);
        $margins->setFooter(0);

        /*
         * Jangan memaksa center horizontal.
         * Biarkan layout template.
         */
        $pageSetup->setHorizontalCentered(
            false
        );

        $pageSetup->setVerticalCentered(
            false
        );
    }

    private function getTahunAnggaran(
        array $data
    ): int|string {
        if (
            !empty($data['tahun_anggaran'])
        ) {
            return (int) $data['tahun_anggaran'];
        }

        if (
            !empty($data['tgl_berangkat'])
        ) {
            $timestamp =
                strtotime(
                    (string) $data['tgl_berangkat']
                );

            if ($timestamp !== false) {
                return (int) date(
                    'Y',
                    $timestamp
                );
            }
        }

        return (int) date('Y');
    }

    private function formatDateForDocument(
        ?string $date
    ): string {
        if (!$date) {
            return '';
        }

        $timestamp =
            strtotime($date);

        return $timestamp === false
            ? $date
            : date(
                'd - m - Y',
                $timestamp
            );
    }

    private function withNipPrefix(
        ?string $nip
    ): string {
        $nip =
            trim((string) $nip);

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

    private function sanitizeFilename(
        string $filename
    ): string {
        $filename =
            preg_replace(
                '/[^A-Za-z0-9._-]+/u',
                '_',
                $filename
            )
            ?? 'Kuitansi.xlsx';

        return trim(
            $filename,
            '._-'
        ) ?: 'Kuitansi.xlsx';
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
