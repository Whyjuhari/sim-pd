<?php

namespace App\Services\Reports;

use App\Support\TravelStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use RuntimeException;

class TravelRecapExporter
{
    public function generate(
        string $audience,
        Builder $query,
        array $summary,
        array $groups,
        array $context
    ): string {
        $spreadsheet = new Spreadsheet();

        try {
            $spreadsheet->removeSheetByIndex(0);
            $this->summarySheet($spreadsheet, $audience, $summary, $context);
            $this->groupSheet($spreadsheet, 'Bulanan', 'Bulan', $groups['months']);
            $this->groupSheet($spreadsheet, 'Tujuan', 'Tujuan', $groups['destinations']);
            $this->groupSheet($spreadsheet, 'MAK', 'MAK / Akun Anggaran', $groups['accounts']);
            if (isset($context['compliance'])) {
                $this->complianceSheet($spreadsheet, $context['compliance']);
            }

            if ($audience === 'program') {
                $this->detailSheet($spreadsheet, $query);
            }

            $directory = (string) config('sim_pd.documents.temporary_dir');
            $this->ensureDirectory($directory);
            $filename = sprintf(
                'Rekap_%s_%s_%s.xlsx',
                ucfirst($audience),
                preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($context['period'] ?? 'semua')),
                Str::lower(Str::random(8))
            );
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return $path;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function summarySheet(Spreadsheet $spreadsheet, string $audience, array $summary, array $context): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($audience === 'program' ? 'Ringkasan' : 'Ringkasan Eksekutif');
        $sheet->setCellValue('A1', $audience === 'program' ? 'REKAP PERJALANAN DINAS' : 'RINGKASAN EKSEKUTIF PERJALANAN DINAS');
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A2', 'Periode');
        $sheet->setCellValue('B2', (string) ($context['period_label'] ?? 'Seluruh data'));
        $sheet->setCellValue('A4', 'Indikator');
        $sheet->setCellValue('B4', 'Nilai');
        $rows = [
            ['Jumlah Penugasan Pegawai', $summary['count']],
            ['Total Estimasi', $summary['estimate']],
            ['Realisasi Disetujui', $summary['realized']],
            ['Selisih', $summary['difference']],
        ];
        $sheet->fromArray($rows, null, 'A5', true);
        $sheet->getStyle('B6:B8')->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $this->styleSheet($sheet, 'A4:B8');
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(24);
        $this->configurePrint($sheet, PageSetup::ORIENTATION_PORTRAIT);
    }

    private function groupSheet(Spreadsheet $spreadsheet, string $title, string $groupLabel, iterable $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray([$groupLabel, 'Jumlah Penugasan', 'Estimasi', 'Realisasi Disetujui', 'Selisih'], null, 'A1');
        $row = 2;
        foreach ($rows as $item) {
            $sheet->fromArray([
                $item['label'], $item['count'], $item['estimate'], $item['realized'], $item['difference'],
            ], null, 'A'.$row);
            $row++;
        }
        $lastRow = max(2, $row - 1);
        $sheet->getStyle("C2:E{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $this->styleSheet($sheet, "A1:E{$lastRow}");
        foreach (['A' => 30, 'B' => 18, 'C' => 20, 'D' => 22, 'E' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getPageSetup()->setPrintArea("A1:E{$lastRow}");
        $this->configurePrint($sheet, PageSetup::ORIENTATION_LANDSCAPE);
    }

    private function detailSheet(Spreadsheet $spreadsheet, Builder $query): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detail Transaksi');
        $sheet->fromArray([
            'Tanggal', 'Nomor SPT', 'Pegawai', 'Tujuan', 'MAK', 'Status',
            'Estimasi', 'Hotel Diajukan', 'Transport Diajukan', 'Hotel Disetujui',
            'Transport Disetujui', 'Total Cair',
        ], null, 'A1');
        $row = 2;
        foreach ((clone $query)->with('pegawai')->lazyById(200) as $travel) {
            $sheet->setCellValue('A'.$row, $travel->tgl_berangkat?->format('d/m/Y'));
            $sheet->setCellValueExplicit('B'.$row, (string) $travel->no_spt, DataType::TYPE_STRING);
            $sheet->fromArray([
                $travel->pegawai?->nama_lengkap ?? '-',
                $travel->kota_tujuan,
                $travel->akun_anggaran,
                TravelStatus::label($travel->status),
                (float) $travel->estimasi_biaya,
                (float) $travel->biaya_hotel_real,
                (float) $travel->biaya_tiket_real,
                (float) $travel->biaya_hotel_approved,
                (float) $travel->biaya_tiket_approved,
                (float) $travel->total_cair,
            ], null, 'C'.$row);
            $row++;
        }
        $lastRow = max(2, $row - 1);
        $sheet->getStyle("G2:L{$lastRow}")->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $this->styleSheet($sheet, "A1:L{$lastRow}");
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['B', 'C', 'D', 'E'], true) ? 24 : 18);
        }
        $sheet->setAutoFilter("A1:L{$lastRow}");
        $sheet->freezePane('A2');
        $sheet->getPageSetup()->setPrintArea("A1:L{$lastRow}");
        $this->configurePrint($sheet, PageSetup::ORIENTATION_LANDSCAPE);
    }

    private function complianceSheet(Spreadsheet $spreadsheet, array $summary): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Kepatuhan PMK');
        $sheet->fromArray(['Indikator', 'Nilai'], null, 'A1');
        $sheet->fromArray([
            ['Rincian biaya diperiksa', (int) ($summary['items'] ?? 0)],
            ['Dalam patokan PMK', (int) ($summary['within'] ?? 0)],
            ['Melebihi patokan PMK', (int) ($summary['over'] ?? 0)],
            ['Menggunakan tarif legacy', (int) ($summary['legacy'] ?? 0)],
            ['Tanpa patokan tersedia', (int) ($summary['unavailable'] ?? 0)],
            ['Perjalanan dengan pengecualian', (int) ($summary['travels_with_exceptions'] ?? 0)],
            ['Total selisih di atas patokan', (float) ($summary['over_amount'] ?? 0)],
            ['Tingkat kepatuhan patokan', ((int) ($summary['compliance_rate'] ?? 0)).'%'],
        ], null, 'A2', true);
        $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $this->styleSheet($sheet, 'A1:B9');
        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getPageSetup()->setPrintArea('A1:B9');
        $this->configurePrint($sheet, PageSetup::ORIENTATION_PORTRAIT);
    }

    private function styleSheet($sheet, string $range): void
    {
        $header = explode(':', $range)[0];
        $lastColumn = preg_replace('/\d+/', '', explode(':', $range)[1]);
        $headerRow = preg_replace('/\D+/', '', $header);
        $sheet->getStyle($header.':'.$lastColumn.$headerRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '173B63']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D8E0E8');
    }

    private function configurePrint($sheet, string $orientation): void
    {
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($orientation)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageMargins()->setTop(.4)->setBottom(.4)->setLeft(.35)->setRight(.35);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Tidak dapat membuat folder ekspor rekap.');
        }
    }
}
