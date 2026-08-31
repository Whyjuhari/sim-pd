<?php

namespace App\Http\Controllers;

use App\Services\Documents\PdfConverter;
use App\Services\Reports\TravelRecapExporter;
use App\Services\Reports\TravelRecapService;
use App\Services\Reports\PmkComplianceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class RecapExportController extends Controller
{
    public function program(
        Request $request,
        string $format,
        TravelRecapService $recap,
        PmkComplianceService $compliance,
        TravelRecapExporter $exporter,
        PdfConverter $converter
    ): BinaryFileResponse {
        $filters = $recap->normalizeProgramFilters($request->validate($recap->programFilterRules()));
        $query = $recap->programQuery($filters);
        $periodLabel = $this->programPeriodLabel($filters);

        return $this->download(
            $format,
            'program',
            $exporter,
            $converter,
            $query,
            $recap->summary($query),
            $recap->grouped($query),
            [
                'period' => ($filters['from'] ?? 'awal').'-'.($filters['to'] ?? 'akhir'),
                'period_label' => $periodLabel,
                'compliance' => $compliance->summarize($query),
            ]
        );
    }

    public function head(
        Request $request,
        string $format,
        TravelRecapService $recap,
        PmkComplianceService $compliance,
        TravelRecapExporter $exporter,
        PdfConverter $converter
    ): BinaryFileResponse {
        $year = min(2100, max(2000, (int) $request->query('year', now()->year)));
        $query = $recap->headQuery($year);

        return $this->download(
            $format,
            'pimpinan',
            $exporter,
            $converter,
            $query,
            $recap->summary($query),
            $recap->grouped($query, $year),
            [
                'period' => (string) $year,
                'period_label' => 'Tahun '.$year,
                'compliance' => $compliance->summarize($query),
            ]
        );
    }

    private function download(
        string $format,
        string $audience,
        TravelRecapExporter $exporter,
        PdfConverter $converter,
        $query,
        array $summary,
        array $groups,
        array $context
    ): BinaryFileResponse {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $xlsxPath = null;
        $pdfPath = null;

        try {
            $xlsxPath = $exporter->generate($audience === 'program' ? 'program' : 'pimpinan', $query, $summary, $groups, $context);
            $downloadName = 'Rekap_'.ucfirst($audience).'_'.$context['period'].'.'.$format;

            if ($format === 'xlsx') {
                return response()->download(
                    $xlsxPath,
                    $downloadName,
                    ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
                )->deleteFileAfterSend(true);
            }

            $pdfPath = $converter->convert($xlsxPath, (string) config('sim_pd.documents.pdf_dir'));
            @unlink($xlsxPath);
            $xlsxPath = null;

            return response()->download(
                $pdfPath,
                $downloadName,
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
            )->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            report($exception);
            foreach ([$xlsxPath, $pdfPath] as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
            abort(500, 'Gagal membuat rekap. Silakan coba kembali atau hubungi administrator.');
        }
    }

    private function programPeriodLabel(array $filters): string
    {
        $period = ($filters['from'] ?? null) || ($filters['to'] ?? null)
            ? (($filters['from'] ?? 'awal').' sampai '.($filters['to'] ?? 'akhir'))
            : 'Seluruh periode';
        $parts = [$period];
        if ($filters['destination'] ?? null) {
            $parts[] = 'Tujuan: '.$filters['destination'];
        }
        if ($filters['account'] ?? null) {
            $parts[] = 'MAK: '.$filters['account'];
        }
        if ($filters['status'] ?? null) {
            $parts[] = 'Status: '.\App\Support\TravelStatus::label($filters['status']);
        }

        return implode(' · ', $parts);
    }
}
