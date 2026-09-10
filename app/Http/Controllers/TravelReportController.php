<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Services\Documents\DocumentCachePrewarmer;
use App\Services\Documents\TravelPdfDocumentService;
use App\Services\Uploads\ReportDocumentationImageStorage;
use App\Support\TravelReportValidation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TravelReportController extends Controller
{
    public function __construct(private readonly DocumentCachePrewarmer $documentPrewarmer) {}

    public function edit(
        Request $request,
        PerjalananDinas $travel
    ): View|RedirectResponse {
        $travel = $this->ownedReadyTravel(
            $request,
            $travel
        );

        if ($travel->laporan) {
            return $this->redirectToRealization(
                $travel,
                'Laporan perjalanan sudah tersimpan dan hanya dapat diisi satu kali. Silakan lanjutkan pengisian realisasi biaya.'
            );
        }

        if (! $travel->pegawai?->signatureAbsolutePath()) {
            return redirect()
                ->route('dashboard.user')
                ->with(
                    'warning',
                    'Laporan belum dapat diisi karena tanda tangan Anda belum tersedia. Silakan hubungi Admin.'
                );
        }

        return view(
            'travel.reports',
            [
                'travel' => $travel,
                'report' => $travel->laporan,
            ]
        );
    }

    public function preview(
        Request $request,
        PerjalananDinas $travel,
        TravelPdfDocumentService $documents
    ): BinaryFileResponse {
        $travel = $this->ownedReadyTravel($request, $travel);

        abort_if(
            $travel->laporan,
            422,
            'Laporan perjalanan sudah tersimpan dan tidak dapat dipratinjau sebagai laporan baru.'
        );
        $this->ensureSignatureAvailable($travel);

        $data = $request->validate(
            TravelReportValidation::rules(),
            TravelReportValidation::messages()
        );
        $photoPaths = array_values(array_filter(array_map(
            static fn ($photo): string|false => $photo->getRealPath(),
            $data['foto_dokumentasi']
        )));

        try {
            $pdfPath = $documents->previewLaporanPerjadin(
                $travel,
                $data,
                $photoPaths
            );
        } catch (Throwable $exception) {
            report($exception);

            abort(
                500,
                'Pratinjau laporan belum dapat dibuat. Silakan coba kembali atau hubungi administrator.'
            );
        }

        $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->sptOperationalReference())
            ?: 'Laporan';
        $safeName = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '_',
            (string) $travel->pegawai->nama_lengkap
        ) ?: 'Pegawai';

        return response()
            ->file($pdfPath, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Pratinjau_Laporan_'.$safeNumber.'_'.$safeName.'.pdf"',
                'Cache-Control' => 'no-store, private, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend(true);
    }

    public function update(
        Request $request,
        PerjalananDinas $travel,
        ReportDocumentationImageStorage $imageStorage
    ): RedirectResponse {

        $travel = $this->ownedReadyTravel(
            $request,
            $travel
        );

        if ($travel->laporan) {
            return $this->redirectToRealization(
                $travel,
                'Laporan perjalanan sudah tersimpan dan tidak dapat diubah. Silakan lanjutkan pengisian realisasi biaya.'
            );
        }
        $this->ensureSignatureAvailable($travel);

        $data = $request->validate(
            TravelReportValidation::rules(),
            TravelReportValidation::messages()
        );

        $storedPaths = [];

        try {
            foreach ($data['foto_dokumentasi'] as $photo) {
                $storedPaths[] = $imageStorage->store(
                    $photo
                );
            }

            $created = DB::transaction(
                function () use (
                    $travel,
                    $data,
                    $storedPaths
                ): bool {
                    $lockedTravel = PerjalananDinas::query()
                        ->whereKey($travel->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedTravel->laporan()->exists()) {
                        return false;
                    }

                    $report = $lockedTravel->laporan()->create([
                        'hasil_pelaksanaan'
                        => $data['hasil_pelaksanaan'],

                        'kesimpulan'
                        => $data['kesimpulan'],

                        'tanggal_laporan'
                        => now()->toDateString(),
                    ]);

                    $report->dokumentasi()->createMany(
                        array_map(
                            fn(string $path, int $index): array => [
                                'path' => $path,
                                'urutan' => $index + 1,
                            ],
                            $storedPaths,
                            array_keys($storedPaths)
                        )
                    );

                    return true;
                }
            );
        } catch (Throwable $exception) {
            $imageStorage->deleteMany($storedPaths);

            throw $exception;
        }

        if (! $created) {
            $imageStorage->deleteMany($storedPaths);

            return $this->redirectToRealization(
                $travel,
                'Laporan perjalanan sudah tersimpan dan tidak dapat diubah. Silakan lanjutkan pengisian realisasi biaya.'
            );
        }

        $this->documentPrewarmer->afterResponse(
            TravelPdfDocumentService::TYPE_REPORT,
            (int) $travel->id
        );

        return redirect()
            ->route(
                'realizations.show',
                [
                    'id' => $travel->id,
                ]
            )
            ->with(
                'success',
                'Laporan perjalanan berhasil disimpan. Silakan lanjutkan pengisian realisasi biaya.'
            );
    }

    private function redirectToRealization(
        PerjalananDinas $travel,
        string $message
    ): RedirectResponse {
        return redirect()
            ->route(
                'realizations.show',
                [
                    'id' => $travel->id,
                ]
            )
            ->with('success', $message);
    }

    private function ensureSignatureAvailable(PerjalananDinas $travel): void
    {
        abort_if(
            ! $travel->pegawai?->signatureAbsolutePath(),
            422,
            'Laporan belum dapat dipratinjau atau disimpan karena tanda tangan Anda belum tersedia. Silakan hubungi Admin.'
        );
    }

    private function ownedReadyTravel(
        Request $request,
        PerjalananDinas $travel
    ): PerjalananDinas {

        abort_if(
            (int) $travel->user_id
                !==
                (int) $request->user()->id,
            404
        );

        abort_if(
            $travel->status
                !==
                PerjalananDinas::STATUS_READY,
            403,
            'Laporan perjalanan tidak dapat diubah karena proses realisasi sudah berjalan.'
        );


        /*
         * Ambil data pegawai dan laporan sekaligus.
         */
        return $travel->loadMissing([
            'pegawai',
            'laporan',
        ]);
    }
}
