<?php

namespace App\Http\Controllers;

use App\Services\Documents\SptPdfSignatureVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class SystemHealthController extends Controller
{
    public function __construct(
        private readonly SptPdfSignatureVerifier $signatureVerifier,
    ) {}

    public function __invoke(): View
    {
        $database = $this->databaseIsAvailable();
        $storageRoot = storage_path('app/private');
        $sptTemplateStorage = Storage::disk('local')->path('spt-templates');
        $templates = collect(config('sim_pd.documents.templates', []));
        $libreOffice = (string) config('sim_pd.documents.libreoffice.binary');
        $pdfSignature = $this->signatureVerifier->health();

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
                'label' => 'Pemeriksa tanda tangan elektronik',
                'ok' => $pdfSignature['ok'],
                'message' => $pdfSignature['message'],
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
}
