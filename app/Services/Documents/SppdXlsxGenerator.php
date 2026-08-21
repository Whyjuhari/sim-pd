<?php

declare(strict_types=1);

namespace App\Services\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use RuntimeException;

final class SppdXlsxGenerator
{
    public function __construct(
        private readonly string $templatePath,
        private readonly string $outputDirectory,
        private readonly array $officials,
        private readonly string $satker = 'BPVP PANGKEP',
        private readonly string $kementerian = 'KEMENTERIAN KETENAGAKERJAAN RI',
    ) {}

    public function generate(array $data): string
    {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException(
                'Template SPPD tidak ditemukan: ' . $this->templatePath
            );
        }

        if (!class_exists(IOFactory::class)) {
            throw new RuntimeException(
                'PhpSpreadsheet belum tersedia. Jalankan composer install terlebih dahulu.'
            );
        }

        $this->ensureDirectory($this->outputDirectory);

        $spreadsheet = IOFactory::load($this->templatePath);

        $sheet = $spreadsheet->getSheetByName('SPPD');

        if ($sheet === null) {
            throw new RuntimeException(
                'Sheet SPPD tidak ditemukan pada template.'
            );
        }

        $this->fillSppd($sheet, $data);

        $this->configurePrint($sheet);

        $safeName = $this->sanitizeFilename(
            sprintf(
                'SPPD_%s_%s',
                $data['no_spt'] ?? 'tanpa-nomor',
                $data['nama_lengkap'] ?? 'pegawai'
            )
        );

        $outputPath =
            rtrim($this->outputDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $safeName
            . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4))
            . '.xlsx';

        $writer = IOFactory::createWriter(
            $spreadsheet,
            'Xlsx'
        );

        /*
         * Biarkan Excel/LibreOffice menghitung formula saat dokumen dibuka.
         * Ini penting karena template resmi masih menggunakan formula.
         */
        $writer->setPreCalculateFormulas(false);

        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    private function fillSppd($sheet, array $data): void
    {
        $ppkName = $this->officials['ppk']['name'] ?? '';
        $ppkNip = $this->officials['ppk']['nip'] ?? '';

        $sheet->setCellValue(
            'J3',
            (string) ($data['no_spt'] ?? '')
        );

        $sheet->setCellValue(
            'F8',
            $ppkName
        );

        $sheet->setCellValue(
            'F11',
            (string) ($data['nama_lengkap'] ?? '')
        );

        $sheet->setCellValue(
            'F12',
            $this->withNipPrefix($data['nip'] ?? '')
        );

        $sheet->setCellValue(
            'G15',
            (string) ($data['pangkat'] ?? '')
        );

        $sheet->setCellValue(
            'G16',
            (string) ($data['jabatan'] ?? '')
        );

        $sheet->setCellValue(
            'G17',
            (string) ($data['tingkat_perjadin'] ?? 'C')
        );

        $sheet->setCellValue(
            'F20',
            (string) ($data['maksud_perjalanan'] ?? '')
        );

        $sheet->setCellValue(
            'F24',
            (string) ($data['angkutan'] ?? '')
        );

        $sheet->setCellValue(
            'G27',
            (string) ($data['tempat_berangkat'] ?? 'Pangkep')
        );

        $sheet->setCellValue(
            'G28',
            (string) ($data['kota_tujuan'] ?? '')
        );

        $lamaHari = (int) ($data['lama_hari'] ?? 0);

        $sheet->setCellValue(
            'G31',
            $lamaHari
        );

        $sheet->setCellValue(
            'H31',
            $this->terbilangHari($lamaHari)
        );

        $sheet->setCellValue(
            'G32',
            $this->formatDate($data['tgl_berangkat'] ?? null)
        );

        $sheet->setCellValue(
            'G33',
            $this->formatDate($data['tgl_kembali'] ?? null)
        );

        $sheet->setCellValue(
            'G43',
            $this->satker
        );

        $sheet->setCellValue(
            'G44',
            (string) ($data['akun_anggaran'] ?? '')
        );

        /* Jangan biarkan nomor contoh dari template muncul sebagai data aktual. */
        $sheet->setCellValue('F46', '');

        $sheet->setCellValue(
            'J51',
            $data['tempat_dokumen'] ?? 'PANGKEP'
        );

        $sheet->setCellValue(
            'J52',
            $this->formatDate(
                $data['tanggal_dokumen'] ?? date('Y-m-d')
            )
        );

        $sheet->setCellValue(
            'H58',
            $ppkName
        );

        $sheet->setCellValue(
            'H59',
            $this->withNipPrefix($ppkNip)
        );
    }

    private function configurePrint($sheet): void
    {
        $pageSetup = $sheet->getPageSetup();

        /*
     * SPPD hanya menggunakan halaman pertama:
     * baris 1 sampai 59 dan seluruh kolom A sampai K.
     */
        $pageSetup->setPrintArea('A1:K59');

        /*
     * Ikuti konfigurasi asli template.
     */
        $pageSetup->setPaperSize(
            PageSetup::PAPERSIZE_A4
        );

        $pageSetup->setOrientation(
            PageSetup::ORIENTATION_PORTRAIT
        );

        /*
     * Template asli menggunakan scaling 65%.
     *
     * Jangan menggunakan FitToWidth/FitToHeight karena
     * layout asli sudah dirancang menggunakan scale.
     */
        $pageSetup->setFitToPage(false);

        $pageSetup->setFitToWidth(0);
        $pageSetup->setFitToHeight(0);

        $pageSetup->setScale(75);

        /*
     * Kembalikan margin seperti template asli.
     */
        $margins = $sheet->getPageMargins();

        $margins->setLeft(0.5);
        $margins->setRight(0.5);
        $margins->setTop(0.5);
        $margins->setBottom(1.0);

        $margins->setHeader(0);
        $margins->setFooter(0);

        /*
     * SPPD tidak perlu dipaksa center secara horizontal.
     * Biarkan mengikuti layout template.
     */
    }

    private function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime($date);

        return $timestamp === false
            ? $date
            : date('d - m - Y', $timestamp);
    }

    private function terbilangHari(int $hari): string
    {
        $words = [
            0 => 'Hari',
            1 => 'Satu',
            2 => 'Dua',
            3 => 'Tiga',
            4 => 'Empat',
            5 => 'Lima',
            6 => 'Enam',
            7 => 'Tujuh',
            8 => 'Delapan',
            9 => 'Sembilan',
            10 => 'Sepuluh',
            11 => 'Sebelas',
        ];

        return '(' . ($words[$hari] ?? (string) $hari) . ') Hari';
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

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace(
            '/[^A-Za-z0-9._-]+/u',
            '_',
            $filename
        ) ?? 'SPPD';

        return trim($filename, '._-') ?: 'SPPD';
    }

    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Tidak dapat membuat folder output: ' . $directory
            );
        }
    }
}
