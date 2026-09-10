<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\SptSrikandiWorkflow;
use App\Services\Documents\TravelPdfDocumentService;
use App\Services\Uploads\SptSrikandiDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly TravelPdfDocumentService $documents,
        private readonly SptSrikandiDocumentStorage $srikandiStorage,
    ) {}

    public function perjadin(Request $request): BinaryFileResponse
    {
        $id = $request->validate([
            'id' => [
                'required',
                'integer',
                'min:1',
            ],
        ])['id'];


        $travel = PerjalananDinas::query()
            ->findOrFail($id);

        Gate::authorize(
            'printTravelDocument',
            $travel
        );

        try {
            $pdfPath = $this->documents->perjadin($travel);
            $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->sptOperationalReference()) ?: 'Perjalanan';
            $filename = 'Dokumen_Perjalanan_'.$safeNumber.'.pdf';

            return response()
                ->file(
                    $pdfPath,
                    [
                        'Content-Type'
                        => 'application/pdf',

                        'Content-Disposition'
                        => 'inline; filename="'
                            . $filename
                            . '"',

                        'Cache-Control'
                        => 'private, max-age=0, must-revalidate',
                    ]
                );
        } catch (\Throwable $exception) {
            report($exception);

            abort(
                500,
                'Gagal membuat dokumen perjalanan. Silakan coba kembali atau hubungi administrator.'
            );
        }
    }

    public function suratTugas(
        Request $request
    ): BinaryFileResponse {

        $id =
            $request->validate([
                'id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ])['id'];

        $travel =
            PerjalananDinas::query()
            ->findOrFail($id);
        Gate::authorize(
            'printSuratTugas',
            $travel
        );
        try {
            $workflow = $travel->spt_group_id
                ? SptSrikandiWorkflow::query()->where('spt_group_id', $travel->spt_group_id)->first()
                : null;

            if ($workflow?->status === SptSrikandiWorkflow::STATUS_PUBLISHED) {
                $pdfPath = $this->srikandiStorage->verifiedAbsolutePath(
                    $workflow->official_pdf_path,
                    $workflow->official_pdf_sha256
                );
            } elseif ($workflow?->draft_pdf_path) {
                $pdfPath = $this->srikandiStorage->verifiedAbsolutePath(
                    $workflow->draft_pdf_path,
                    $workflow->draft_pdf_sha256
                );
            } else {
                $pdfPath = $this->documents->suratTugas($travel);
            }

            if (! $pdfPath) {
                throw new \RuntimeException('Berkas Surat Tugas privat tidak tersedia atau tidak valid.');
            }

            $safeNumber =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $travel->sptOperationalReference()
                )
                ?: 'SPT';

            return response()
                ->file(
                    $pdfPath,
                    [
                        'Content-Type'
                        => 'application/pdf',

                        'Content-Disposition'
                        => 'inline; filename="SPT_'
                            . $safeNumber
                            . '.pdf"',

                        'Cache-Control'
                        => 'private, max-age=0, must-revalidate',
                    ]
                );
        } catch (\Throwable $exception) {
            report($exception);

            abort(
                500,
                'Gagal membuat Surat Tugas. Silakan coba kembali atau hubungi administrator.'
            );
        }
    }
    public function laporanPerjadin(
        Request $request
    ): BinaryFileResponse|RedirectResponse {
        $id =
            $request->validate([
                'id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ])['id'];

        $travel = PerjalananDinas::query()
            ->with(['pegawai', 'laporan.dokumentasi'])
            ->find($id);

        abort_if(! $travel || ! $travel->pegawai, 404, 'Data perjalanan dinas tidak ditemukan.');
        abort_if((int) $travel->user_id !== (int) $request->user()->id, 404);
        abort_if(! $travel->laporan, 422, 'Laporan Perjalanan Dinas belum diisi.');

        if (! $travel->pegawai->signatureAbsolutePath()) {
            return redirect()
                ->route('dashboard.user')
                ->with(
                    'warning',
                    'Laporan belum dapat dicetak karena tanda tangan Anda belum tersedia. Silakan hubungi Admin.'
                );
        }
        try {
            $pdfPath = $this->documents->laporanPerjadin($travel);

            $safeNumber =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $travel->sptOperationalReference()
                )
                ?: 'Laporan';


            $safeName =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $travel->pegawai->nama_lengkap
                )
                ?: 'Pegawai';


            return response()
                ->file(
                    $pdfPath,
                    [
                        'Content-Type'
                        => 'application/pdf',

                        'Content-Disposition'
                        => 'inline; filename="Laporan_Perjadin_'
                            . $safeNumber
                            . '_'
                            . $safeName
                            . '.pdf"',

                        'Cache-Control'
                        => 'private, max-age=0, must-revalidate',
                    ]
                );
        } catch (\Throwable $exception) {
            report(
                $exception
            );


            abort(
                500,
                'Gagal membuat Laporan Perjalanan Dinas. Silakan coba kembali atau hubungi administrator.'
            );
        }
    }
}
