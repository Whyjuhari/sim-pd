<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Services\Uploads\ReportDocumentationImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class TravelReportController extends Controller
{

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

        return view(
            'travel.reports',
            [
                'travel' => $travel,
                'report' => $travel->laporan,
            ]
        );
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

        $documentation = config(
            'sim_pd.documents.report_documentation'
        );
        $maxFiles = max(
            1,
            min(2, (int) ($documentation['max_files'] ?? 2))
        );
        $maxKilobytes = max(
            1,
            (int) ($documentation['max_kilobytes_per_file'] ?? 5120)
        );
        $minDimension = max(
            1,
            (int) ($documentation['min_dimension'] ?? 600)
        );
        $maxDimension = max(
            $minDimension,
            (int) ($documentation['max_dimension'] ?? 6000)
        );

        $data = $request->validate(
            [
                'hasil_pelaksanaan' => [
                    'required',
                    'string',
                    'max:20000',
                ],

                'kesimpulan' => [
                    'required',
                    'string',
                    'max:10000',
                ],

                'foto_dokumentasi' => [
                    'required',
                    'array',
                    'min:1',
                    'max:' . $maxFiles,
                ],

                'foto_dokumentasi.*' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png',
                    'mimetypes:image/jpeg,image/png',
                    'max:' . $maxKilobytes,
                    'dimensions:min_width=' . $minDimension
                        . ',min_height=' . $minDimension
                        . ',max_width=' . $maxDimension
                        . ',max_height=' . $maxDimension,
                ],
            ],
            [
                'foto_dokumentasi.required' =>
                'Minimal satu foto dokumentasi wajib diunggah.',
                'foto_dokumentasi.max' =>
                'Maksimal dua foto dokumentasi dapat diunggah.',
                'foto_dokumentasi.*.image' =>
                'Dokumentasi wajib berupa gambar yang valid.',
                'foto_dokumentasi.*.mimes' =>
                'Format dokumentasi hanya boleh JPG, JPEG, atau PNG.',
                'foto_dokumentasi.*.mimetypes' =>
                'Tipe file dokumentasi tidak valid.',
                'foto_dokumentasi.*.max' =>
                'Ukuran setiap foto dokumentasi maksimal 5 MB.',
                'foto_dokumentasi.*.dimensions' =>
                'Dimensi foto dokumentasi harus antara 600 × 600 dan 6000 × 6000 piksel.',
            ]
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
                            fn (string $path, int $index): array => [
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


        /*
         * Setelah laporan selesai:
         *
         * lanjut ke form biaya hotel + tiket.
         */
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


    /*
     * =====================================================
     * HELPER KEPEMILIKAN PERJALANAN
     * =====================================================
     */
    private function ownedReadyTravel(
        Request $request,
        PerjalananDinas $travel
    ): PerjalananDinas {
        /*
         * Kalau perjalanan bukan milik pegawai login,
         * tampilkan 404.
         *
         * Dengan begitu kita tidak membocorkan
         * keberadaan perjalanan milik pegawai lain.
         */
        abort_if(
            (int) $travel->user_id
                !==
                (int) $request->user()->id,
            404
        );


        /*
         * Laporan hanya boleh diedit sebelum
         * realisasi dikirim.
         */
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
