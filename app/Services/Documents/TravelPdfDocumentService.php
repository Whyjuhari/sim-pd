<?php

namespace App\Services\Documents;

use App\Models\PerjalananDinas;
use App\Repositories\PerjalananDinasRepository;
use App\Support\SptTemplateVariant;

class TravelPdfDocumentService
{
    public const TYPE_TRAVEL = 'travel-document';
    public const TYPE_SPT = 'surat-tugas';
    public const TYPE_REPORT = 'travel-report';

    public function __construct(
        private readonly PerjalananDinasRepository $repository,
        private readonly GeneratedPdfCache $cache
    ) {}

    public function perjadin(PerjalananDinas $travel): string
    {
        $data = $this->documentData($travel->id);
        $documents = config('sim_pd.documents');
        $officials = config('sim_pd.officials');
        $temporaryFiles = [];

        try {
            return $this->cache->remember(
                self::TYPE_TRAVEL,
                'travel-'.$travel->id,
                [
                    'data' => $data,
                    'officials' => $officials,
                ],
                $this->dependencies(
                    [
                        SppdXlsxGenerator::class,
                        LumpsumXlsxGenerator::class,
                        KuitansiXlsxGenerator::class,
                        PdfConverter::class,
                    ],
                    [$documents['templates']['sppd']]
                ),
                function () use ($data, $documents, $officials, &$temporaryFiles): string {
                    $temporaryFiles[] = $stage1 = (new SppdXlsxGenerator(
                        $documents['templates']['sppd'],
                        $documents['temporary_dir'],
                        $officials,
                        $officials['satker'],
                        $officials['kementerian'],
                    ))->generate($data);

                    $temporaryFiles[] = $stage2 = (new LumpsumXlsxGenerator(
                        $documents['templates']['sppd'],
                        $documents['temporary_dir'],
                        $officials,
                    ))->generate($data, $stage1);

                    $temporaryFiles[] = $finalWorkbook = (new KuitansiXlsxGenerator(
                        $documents['templates']['sppd'],
                        $documents['temporary_dir'],
                        $officials,
                    ))->generate($data, $stage2);

                    return $this->converter()->convert(
                        $finalWorkbook,
                        $documents['pdf_dir']
                    );
                }
            );
        } finally {
            foreach ($temporaryFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    public function suratTugas(PerjalananDinas $travel): string
    {
        $data = $this->suratTugasData($travel->id);
        $documents = config('sim_pd.documents');
        $docxPath = null;

        $templatePath = $this->suratTugasTemplatePath(
            $travel,
            count($data['pegawai_list'] ?? [])
        );

        try {
            $scope = $travel->spt_group_id
                ? 'group-'.$travel->spt_group_id
                : 'legacy-'.$travel->id;

            return $this->cache->remember(
                self::TYPE_SPT,
                $scope,
                [
                    'data' => $data,
                    'template_path' => $templatePath,
                    'template_sha256' => is_file($templatePath) ? hash_file('sha256', $templatePath) : null,
                ],
                $this->dependencies(
                    [SuratTugasDocxGenerator::class, PdfConverter::class],
                    [$templatePath]
                ),
                function () use ($data, $documents, $templatePath, &$docxPath): string {
                    $docxPath = (new SuratTugasDocxGenerator(
                        $templatePath,
                        $documents['temporary_dir'],
                    ))->generate($data);

                    return $this->converter()->convert(
                        $docxPath,
                        $documents['pdf_dir']
                    );
                }
            );
        } finally {
            if ($docxPath && is_file($docxPath)) {
                @unlink($docxPath);
            }
        }
    }

    public function suratTugasDocx(PerjalananDinas $travel): string
    {
        $data = $this->suratTugasData($travel->id);
        $documents = config('sim_pd.documents');
        $templatePath = $this->suratTugasTemplatePath(
            $travel,
            count($data['pegawai_list'] ?? [])
        );

        return (new SuratTugasDocxGenerator(
            $templatePath,
            $documents['temporary_dir'],
        ))->generate($data);
    }

    private function suratTugasTemplatePath(
        PerjalananDinas $travel,
        int $employeeCount,
    ): string
    {
        if (SptTemplateVariant::exists($travel->spt_template_variant)) {
            return SptTemplateVariant::pathForEmployeeCount(
                $travel->spt_template_variant,
                $employeeCount,
                (int) config('sim_pd.documents.spt_inline_employee_limit', 2),
            );
        }

        $travel->loadMissing('sptTemplate');

        if ($travel->sptTemplate?->existsOnDisk()) {
            return $travel->sptTemplate->absolutePath();
        }

        return (string) config('sim_pd.documents.templates.surat_tugas');
    }

    public function laporanPerjadin(PerjalananDinas $travel): string
    {
        $data = $this->laporanPerjadinData($travel);
        $documents = config('sim_pd.documents');
        $docxPath = null;

        try {
            return $this->cache->remember(
                self::TYPE_REPORT,
                'travel-'.$travel->id,
                [
                    'data' => $data,
                    'report_documentation' => $documents['report_documentation'],
                ],
                $this->dependencies(
                    [LaporanPerjadinDocxGenerator::class, PdfConverter::class],
                    [
                        $documents['templates']['laporan_perjadin'],
                        $data['ttd_absolute_path'],
                        ...$data['foto_dokumentasi_paths'],
                    ]
                ),
                function () use ($data, $documents, &$docxPath): string {
                    $docxPath = (new LaporanPerjadinDocxGenerator(
                        $documents['templates']['laporan_perjadin'],
                        $documents['temporary_dir'],
                    ))->generate($data);

                    return $this->converter()->convert(
                        $docxPath,
                        $documents['pdf_dir']
                    );
                }
            );
        } finally {
            if ($docxPath && is_file($docxPath)) {
                @unlink($docxPath);
            }
        }
    }

    /**
     * Membuat PDF laporan dari isian yang belum disimpan.
     *
     * @param  array{hasil_pelaksanaan: string, kesimpulan: string}  $reportData
     * @param  array<int, string>  $photoPaths
     */
    public function previewLaporanPerjadin(
        PerjalananDinas $travel,
        array $reportData,
        array $photoPaths
    ): string {
        $travel->loadMissing('pegawai');
        abort_if(! $travel->pegawai, 404, 'Data perjalanan dinas tidak ditemukan.');

        $data = [
            'nama_lengkap' => $travel->pegawai->nama_lengkap,
            'nip' => $travel->pegawai->nip,
            'jabatan' => $travel->pegawai->jabatan,
            'pangkat_golongan' => $travel->pegawai->pangkat_golongan,
            'ttd_absolute_path' => $travel->pegawai->signatureAbsolutePath(),
            'no_spt' => $travel->sptOperationalReference(),
            'tgl_berangkat' => $travel->getRawOriginal('tgl_berangkat'),
            'tgl_kembali' => $travel->getRawOriginal('tgl_kembali'),
            'kota_tujuan' => $travel->kota_tujuan,
            'maksud_perjalanan' => $travel->maksud_perjalanan,
            'akun_anggaran' => $travel->akun_anggaran,
            'hasil_pelaksanaan' => $reportData['hasil_pelaksanaan'],
            'kesimpulan' => $reportData['kesimpulan'],
            'tanggal_laporan' => now()->toDateString(),
            'foto_dokumentasi_paths' => $photoPaths,
        ];
        $documents = config('sim_pd.documents');
        $docxPath = null;
        $expectedPdfPath = null;

        try {
            $docxPath = (new LaporanPerjadinDocxGenerator(
                $documents['templates']['laporan_perjadin'],
                $documents['temporary_dir'],
            ))->generate($data);
            $expectedPdfPath = rtrim(
                $documents['pdf_dir'],
                DIRECTORY_SEPARATOR
            ).DIRECTORY_SEPARATOR.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

            return $this->converter()->convert(
                $docxPath,
                $documents['pdf_dir']
            );
        } catch (\Throwable $exception) {
            if ($expectedPdfPath && is_file($expectedPdfPath)) {
                @unlink($expectedPdfPath);
            }

            throw $exception;
        } finally {
            if ($docxPath && is_file($docxPath)) {
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
            'tahun_anggaran' => ! empty($data['tgl_berangkat'])
                ? date('Y', strtotime($data['tgl_berangkat']))
                : now()->year,
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
            'tahun_anggaran' => ! empty($data['tgl_berangkat'])
                ? date('Y', strtotime($data['tgl_berangkat']))
                : now()->year,
        ];
    }

    private function laporanPerjadinData(PerjalananDinas $travel): array
    {
        $travel->loadMissing(['pegawai', 'laporan.dokumentasi']);
        abort_if(! $travel->pegawai, 404, 'Data perjalanan dinas tidak ditemukan.');
        abort_if(! $travel->laporan, 422, 'Laporan Perjalanan Dinas belum diisi.');

        return [
            'nama_lengkap' => $travel->pegawai->nama_lengkap,
            'nip' => $travel->pegawai->nip,
            'jabatan' => $travel->pegawai->jabatan,
            'pangkat_golongan' => $travel->pegawai->pangkat_golongan,
            'ttd_absolute_path' => $travel->pegawai->signatureAbsolutePath(),
            'no_spt' => $travel->sptOperationalReference(),
            'tgl_berangkat' => $travel->getRawOriginal('tgl_berangkat'),
            'tgl_kembali' => $travel->getRawOriginal('tgl_kembali'),
            'kota_tujuan' => $travel->kota_tujuan,
            'maksud_perjalanan' => $travel->maksud_perjalanan,
            'akun_anggaran' => $travel->akun_anggaran,
            'hasil_pelaksanaan' => $travel->laporan->hasil_pelaksanaan,
            'kesimpulan' => $travel->laporan->kesimpulan,
            'tanggal_laporan' => $travel->laporan->tanggal_laporan,
            'foto_dokumentasi_paths' => $travel->laporan->dokumentasi
                ->map(fn ($documentation): ?string => $documentation->absolutePath())
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function converter(): PdfConverter
    {
        return new PdfConverter(
            config('sim_pd.documents.libreoffice.binary'),
            (int) config('sim_pd.documents.libreoffice.timeout', 60),
        );
    }

    /**
     * @param  array<int, class-string>  $classes
     * @param  array<int, string|null>  $files
     * @return array<int, string|null>
     */
    private function dependencies(array $classes, array $files = []): array
    {
        foreach ($classes as $class) {
            $files[] = (new \ReflectionClass($class))->getFileName() ?: null;
        }

        return $files;
    }
}
