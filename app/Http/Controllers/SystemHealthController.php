<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\Process\Process;
use Throwable;

class SystemHealthController extends Controller
{
    public function __invoke(): View
    {
        $database = $this->databaseIsAvailable();
        $storageRoot = storage_path('app/private');
        $sptTemplateStorage = Storage::disk('local')->path('spt-templates');
        $templates = collect(config('sim_pd.documents.templates', []));
        $libreOffice = (string) config('sim_pd.documents.libreoffice.binary');
        $phpCli = $this->binaryReportsVersion(
            (string) config('sim_pd.documents.php_cli_binary', 'php'),
            '/^PHP \d+\.\d+/m',
        );
        $pdfTextReader = $this->binaryReportsVersion(
            (string) config('sim_pd.documents.pdf_text.binary', 'pdftotext'),
            '/\bpdftotext\s+version\b/i',
        );

        $checks = [
            ['label' => 'Database', 'ok' => $database, 'message' => $database ? 'Koneksi tersedia' : 'Koneksi gagal'],
            [
                'label' => 'Penyimpanan privat',
                'ok' => is_dir($storageRoot) && is_writable($storageRoot),
                'message' => is_dir($storageRoot) && is_writable($storageRoot) ? 'Dapat ditulis' : 'Tidak dapat ditulis',
            ],
            [
                'label' => 'Penyimpanan template SPT',
                'ok' => is_dir($sptTemplateStorage) && is_writable($sptTemplateStorage),
                'message' => is_dir($sptTemplateStorage) && is_writable($sptTemplateStorage) ? 'Dapat ditulis' : 'Tidak dapat ditulis atau folder belum tersedia',
            ],
            [
                'label' => 'Template dokumen',
                'ok' => $templates->isNotEmpty() && $templates->every(fn (string $path): bool => is_file($path) && is_readable($path)),
                'message' => $templates->isNotEmpty() && $templates->every(fn (string $path): bool => is_file($path) && is_readable($path))
                    ? 'Semua template tersedia'
                    : 'Ada template yang tidak tersedia',
            ],
            [
                'label' => 'LibreOffice',
                'ok' => is_file($libreOffice),
                'message' => is_file($libreOffice) ? 'Binary tersedia' : 'Binary belum tersedia',
            ],
            [
                'label' => 'PHP CLI',
                'ok' => $phpCli,
                'message' => $phpCli ? 'PHP CLI dapat dijalankan' : 'PHP CLI belum tersedia atau tidak dapat dijalankan',
            ],
            [
                'label' => 'Pembaca teks PDF',
                'ok' => $pdfTextReader,
                'message' => $pdfTextReader ? 'pdftotext dapat dijalankan' : 'pdftotext belum tersedia atau tidak dapat dijalankan',
            ],
        ];

        return view('admin.system-health', [
            'checks' => $checks,
            'allHealthy' => collect($checks)->every('ok'),
        ]);
    }

    private function databaseIsAvailable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function binaryReportsVersion(string $binary, string $pattern): bool
    {
        $binary = trim($binary);
        if ($binary === '') {
            return false;
        }

        try {
            $process = new Process([$binary, '-v'], base_path());
            $process->setTimeout(5);
            $process->run();

            // Beberapa build Xpdf mengembalikan kode 99 untuk -v meskipun
            // executable sehat, sehingga identifikasi memakai output versi.
            return preg_match(
                $pattern,
                $process->getOutput().$process->getErrorOutput(),
            ) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
