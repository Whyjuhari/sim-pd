<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Repositories\PerjalananDinasRepository;
use App\Services\Documents\KuitansiXlsxGenerator;
use App\Services\Documents\LaporanPerjadinDocxGenerator;
use App\Services\Documents\LumpsumXlsxGenerator;
use App\Services\Documents\PdfConverter;
use App\Services\Documents\SppdXlsxGenerator;
use App\Services\Documents\SuratTugasDocxGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly PerjalananDinasRepository $repository) {}


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

        $data = $this->documentData(
            $travel->id
        );

        $documents = config('sim_pd.documents');
        $officials = config('sim_pd.officials');

        $temporaryFiles = [];
        $pdfPath = null;

        try {
            $temporaryFiles[] = $stage1 =
                (new SppdXlsxGenerator(
                    $documents['templates']['sppd'],
                    $documents['temporary_dir'],
                    $officials,
                    $officials['satker'],
                    $officials['kementerian'],
                ))->generate($data);

            $temporaryFiles[] = $stage2 =
                (new LumpsumXlsxGenerator(
                    $documents['templates']['sppd'],
                    $documents['temporary_dir'],
                    $officials,
                ))->generate(
                    $data,
                    $stage1
                );

            $temporaryFiles[] = $finalWorkbook =
                (new KuitansiXlsxGenerator(
                    $documents['templates']['sppd'],
                    $documents['temporary_dir'],
                    $officials,
                ))->generate(
                    $data,
                    $stage2
                );

            $pdfPath = $this->converter()->convert(
                $finalWorkbook,
                $documents['pdf_dir']
            );

            $filename = basename($pdfPath);

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
                )
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {

            if (
                $pdfPath
                && is_file($pdfPath)
            ) {
                @unlink($pdfPath);
            }

            report($exception);

            abort(
                500,
                'Gagal membuat dokumen perjalanan. Silakan coba kembali atau hubungi administrator.'
            );
        } finally {

            foreach (
                $temporaryFiles
                as $file
            ) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
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
        $data =
            $this->suratTugasData(
                $travel->id
            );

        $documents =
            config('sim_pd.documents');

        $docxPath = null;
        $pdfPath = null;
        try {
            $docxPath =
                (
                    new SuratTugasDocxGenerator(
                        $documents['templates']['surat_tugas'],
                        $documents['temporary_dir'],
                    )
                )->generate(
                    $data
                );
            $pdfPath =
                $this->converter()
                ->convert(
                    $docxPath,
                    $documents['pdf_dir']
                );

            $safeNumber =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $data['no_spt']
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
                )
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {

            if (
                $pdfPath
                && is_file($pdfPath)
            ) {
                @unlink($pdfPath);
            }

            report($exception);

            abort(
                500,
                'Gagal membuat Surat Tugas. Silakan coba kembali atau hubungi administrator.'
            );
        } finally {
            if (
                $docxPath
                && is_file($docxPath)
            ) {
                @unlink($docxPath);
            }
        }
    }

    private function documentData(int $id): array
    {
        $data = $this->repository->findForDocument($id);
        abort_if($data === null, 404, 'Data perjalanan dinas tidak ditemukan.');

        return [
            ...$data,
            'tanggal_dokumen' => now()->toDateString(),
            'tempat_dokumen' => config('sim_pd.officials.satker', 'PANGKEP'),
            'tingkat_perjadin' => config('sim_pd.documents.tingkat_perjadin', 'C'),
            'tahun_anggaran' => ! empty($data['tgl_berangkat']) ? date('Y', strtotime($data['tgl_berangkat'])) : now()->year,
        ];
    }

    private function suratTugasData(int $id): array
    {
        $data = $this->repository->findSuratTugasGroup($id);
        abort_if($data === null, 404, 'Data Surat Tugas tidak ditemukan.');

        return [
            ...$data,
            'tanggal_dokumen' => now()->toDateString(),
            'tempat_dokumen' => config('sim_pd.officials.satker', 'PANGKEP'),
            'tingkat_perjadin' => config('sim_pd.documents.tingkat_perjadin', 'C'),
            'tahun_anggaran' => ! empty($data['tgl_berangkat']) ? date('Y', strtotime($data['tgl_berangkat'])) : now()->year,
        ];
    }

    private function converter(): PdfConverter
    {
        return new PdfConverter(
            config('sim_pd.documents.libreoffice.binary'),
            (int) config('sim_pd.documents.libreoffice.timeout', 60),
        );
    }
    public function laporanPerjadin(
        Request $request
    ): BinaryFileResponse|RedirectResponse {
        // dd($request->all());
        $id =
            $request->validate([
                'id' => [
                    'required',
                    'integer',
                    'min:1',
                ],
            ])['id'];

        $data =
            $this->laporanPerjadinData(
                $id,
                (int) $request->user()->id
            );

        if (! $data['ttd_absolute_path']) {
            return redirect()
                ->route('dashboard.user')
                ->with(
                    'warning',
                    'Laporan belum dapat dicetak karena tanda tangan Anda belum tersedia. Silakan hubungi Admin.'
                );
        }


        $documents =
            config('sim_pd.documents');

        $docxPath = null;
        $pdfPath = null;


        try {
            /*
         * =========================================
         * WORD
         * =========================================
         */

            $docxPath =
                (
                    new LaporanPerjadinDocxGenerator(
                        $documents['templates']['laporan_perjadin'],

                        $documents['temporary_dir'],
                    )
                )->generate(
                    $data
                );


            /*
         * =========================================
         * WORD → PDF
         * =========================================
         */

            $pdfPath =
                $this->converter()
                ->convert(
                    $docxPath,
                    $documents['pdf_dir']
                );


            /*
         * =========================================
         * NAMA FILE PDF
         * =========================================
         */

            $safeNumber =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $data['no_spt']
                )
                ?: 'Laporan';


            $safeName =
                preg_replace(
                    '/[^A-Za-z0-9._-]+/',
                    '_',
                    $data['nama_lengkap']
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
                )
                ->deleteFileAfterSend(
                    true
                );
        } catch (\Throwable $exception) {

            if (
                $pdfPath
                && is_file($pdfPath)
            ) {
                @unlink(
                    $pdfPath
                );
            }


            report(
                $exception
            );


            abort(
                500,
                'Gagal membuat Laporan Perjalanan Dinas. Silakan coba kembali atau hubungi administrator.'
            );
        } finally {

            /*
         * DOCX temporary dibersihkan.
         */
            if (
                $docxPath
                && is_file($docxPath)
            ) {
                @unlink(
                    $docxPath
                );
            }
        }
    }
    private function laporanPerjadinData(
        int $id,
        int $authenticatedUserId
    ): array {

        $travel =
            PerjalananDinas::query()
            ->with([
                'pegawai',
                'laporan.dokumentasi',
            ])
            ->find($id);


        /*
     * Travel tidak ada.
     */
        abort_if(
            ! $travel
                || ! $travel->pegawai,
            404,
            'Data perjalanan dinas tidak ditemukan.'
        );



        abort_if(
            (int) $travel->user_id
                !==
                $authenticatedUserId,
            404
        );


        abort_if(
            ! $travel->laporan,
            422,
            'Laporan Perjalanan Dinas belum diisi.'
        );

        $signaturePath =
            $travel
            ->pegawai
            ->signatureAbsolutePath();


        return [
            /*
         * Pegawai
         */
            'nama_lengkap'
            => $travel
                ->pegawai
                ->nama_lengkap,

            'nip'
            => $travel
                ->pegawai
                ->nip,

            'jabatan'
            => $travel
                ->pegawai
                ->jabatan,

            'pangkat_golongan'
            => $travel
                ->pegawai
                ->pangkat_golongan,

            'ttd_absolute_path'
            => $signaturePath,


            /*
         * Perjalanan
         */
            'no_spt'
            => $travel->no_spt,

            'tgl_berangkat'
            => $travel
                ->getRawOriginal(
                    'tgl_berangkat'
                ),

            'tgl_kembali'
            => $travel
                ->getRawOriginal(
                    'tgl_kembali'
                ),

            'kota_tujuan'
            => $travel
                ->kota_tujuan,

            'maksud_perjalanan'
            => $travel
                ->maksud_perjalanan,

            'akun_anggaran'
            => $travel
                ->akun_anggaran,


            /*
         * Laporan
         */
            'hasil_pelaksanaan'
            => $travel
                ->laporan
                ->hasil_pelaksanaan,

            'kesimpulan'
            => $travel
                ->laporan
                ->kesimpulan,

            'tanggal_laporan'
            => $travel
                ->laporan
                ->tanggal_laporan,

            'foto_dokumentasi_paths'
            => $travel
                ->laporan
                ->dokumentasi
                ->map(
                    fn ($documentation): ?string =>
                    $documentation->absolutePath()
                )
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
