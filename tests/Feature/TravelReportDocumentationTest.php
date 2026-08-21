<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\User;
use App\Services\Documents\LaporanPerjadinDocxGenerator;
use App\Services\Uploads\ReportDocumentationImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class TravelReportDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/private/documents/{pdf,temporary}/*'), GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_employee_can_store_one_or_two_securely_normalized_documentation_photos(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);

        $this->actingAs($owner)
            ->put(
                route('travel-reports.update', ['travel' => $travel->id]),
                [
                    'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
                    'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
                    'foto_dokumentasi' => [
                        UploadedFile::fake()->image(
                            'kegiatan-pertama.jpg',
                            1600,
                            900
                        )->size(1024),
                        UploadedFile::fake()->image(
                            'kegiatan-kedua.png',
                            900,
                            1600
                        )->size(1024),
                    ],
                ]
            )
            ->assertRedirect(
                route('realizations.show', ['id' => $travel->id])
            );

        $report = $travel->fresh()
            ->laporan()
            ->with('dokumentasi')
            ->firstOrFail();

        $this->assertCount(2, $report->dokumentasi);
        $this->assertSame([1, 2], $report->dokumentasi->pluck('urutan')->all());

        foreach ($report->dokumentasi as $documentation) {
            $this->assertMatchesRegularExpression(
                '#\Areport-documentation/[0-9a-f-]+\.jpg\z#D',
                $documentation->path
            );
            $this->assertStringNotContainsString(
                'kegiatan-',
                $documentation->path
            );
            Storage::disk('local')->assertExists(
                $documentation->path
            );

            $imageInfo = getimagesize(
                Storage::disk('local')->path(
                    $documentation->path
                )
            );

            $this->assertIsArray($imageInfo);
            $this->assertSame(1200, $imageInfo[0]);
            $this->assertSame(1200, $imageInfo[1]);
            $this->assertSame('image/jpeg', $imageInfo['mime']);
        }
    }

    public function test_missing_unsafe_oversized_or_excess_photos_are_rejected(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $url = route('travel-reports.update', ['travel' => $travel->id]);
        $text = [
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
        ];

        $this->actingAs($owner)
            ->put($url, $text)
            ->assertSessionHasErrors('foto_dokumentasi');

        $this->actingAs($owner)
            ->put($url, [
                ...$text,
                'foto_dokumentasi' => [
                    UploadedFile::fake()->create(
                        'dokumen-disamarkan.jpg',
                        100,
                        'application/pdf'
                    ),
                ],
            ])
            ->assertSessionHasErrors('foto_dokumentasi.0');

        $this->actingAs($owner)
            ->put($url, [
                ...$text,
                'foto_dokumentasi' => [
                    UploadedFile::fake()->image(
                        'terlalu-besar.jpg',
                        1200,
                        900
                    )->size(5121),
                ],
            ])
            ->assertSessionHasErrors('foto_dokumentasi.0');

        $this->actingAs($owner)
            ->put($url, [
                ...$text,
                'foto_dokumentasi' => [
                    UploadedFile::fake()->image('satu.jpg', 1200, 900),
                    UploadedFile::fake()->image('dua.jpg', 1200, 900),
                    UploadedFile::fake()->image('tiga.jpg', 1200, 900),
                ],
            ])
            ->assertSessionHasErrors('foto_dokumentasi');

        $this->assertDatabaseMissing('laporan_perjadin', [
            'perjalanan_dinas_id' => $travel->id,
        ]);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(
                'report-documentation'
            )
        );
    }

    public function test_repeat_submission_cannot_replace_photos_or_leave_new_files(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $report = $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Isi laporan pertama.',
            'kesimpulan' => 'Kesimpulan pertama.',
            'tanggal_laporan' => '2026-08-20',
        ]);
        $existingPath = 'report-documentation/' . Str::uuid() . '.jpg';
        Storage::disk('local')->put($existingPath, 'existing-image');
        $report->dokumentasi()->create([
            'path' => $existingPath,
            'urutan' => 1,
        ]);

        $this->actingAs($owner)
            ->put(
                route('travel-reports.update', ['travel' => $travel->id]),
                [
                    'hasil_pelaksanaan' => 'Upaya mengganti laporan.',
                    'kesimpulan' => 'Upaya mengganti kesimpulan.',
                    'foto_dokumentasi' => [
                        UploadedFile::fake()->image(
                            'pengganti.jpg',
                            1200,
                            900
                        ),
                    ],
                ]
            )
            ->assertRedirect(
                route('realizations.show', ['id' => $travel->id])
            );

        $this->assertDatabaseCount(
            'laporan_perjadin_dokumentasi',
            1
        );
        $this->assertSame(
            [$existingPath],
            Storage::disk('local')->allFiles(
                'report-documentation'
            )
        );
        $this->assertSame(
            'Isi laporan pertama.',
            $report->fresh()->hasil_pelaksanaan
        );
    }

    public function test_report_template_keeps_section_headings_with_content_and_documentation_on_dedicated_page(): void
    {
        $templatePath = resource_path(
            'documents/Template_Laporan_Perjadin.docx'
        );
        $archive = new ZipArchive;

        $this->assertTrue(
            $archive->open($templatePath) === true,
            'Template laporan perjadin tidak dapat dibuka.'
        );

        $documentXml = $archive->getFromName('word/document.xml');
        $archive->close();

        $this->assertIsString($documentXml);

        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($documentXml));

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace(
            'w',
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
        );

        foreach ([
            'Hasil Pelaksanaan' => '${hasil_pelaksanaan}',
            'Kesimpulan' => '${kesimpulan}',
        ] as $headingText => $placeholder) {
            $sectionHeadings = $xpath->query(
                '//w:body/w:p[normalize-space(string(.)) = "'
                    . $headingText
                    . '"]'
            );

            $this->assertNotFalse($sectionHeadings);
            $this->assertCount(1, $sectionHeadings);

            $sectionHeading = $sectionHeadings->item(0);
            $this->assertNotNull($sectionHeading);
            $this->assertSame(
                1,
                $xpath->query(
                    './w:pPr/w:keepNext',
                    $sectionHeading
                )?->length,
                $headingText . ' harus tetap bersama awal isinya.'
            );
            $this->assertSame(
                0,
                $xpath->query(
                    './w:pPr/w:pageBreakBefore',
                    $sectionHeading
                )?->length,
                $headingText . ' tidak boleh selalu memulai halaman baru.'
            );

            $contentParagraph = $xpath->query(
                './following-sibling::w:p[1]',
                $sectionHeading
            )?->item(0);

            $this->assertNotNull($contentParagraph);
            $this->assertStringContainsString(
                $placeholder,
                $contentParagraph->textContent
            );
            $this->assertSame(
                0,
                $xpath->query(
                    './w:pPr/w:keepLines',
                    $contentParagraph
                )?->length,
                'Isi panjang tetap harus dapat berlanjut ke halaman berikutnya.'
            );
        }

        $headings = $xpath->query(
            '//w:body/w:p[normalize-space(string(.)) = "Foto Dokumentasi :"]'
        );

        $this->assertNotFalse($headings);
        $this->assertCount(1, $headings);

        $heading = $headings->item(0);
        $this->assertNotNull($heading);
        $this->assertSame(
            1,
            $xpath->query('./w:pPr/w:pageBreakBefore', $heading)?->length,
            'Judul dokumentasi harus selalu dimulai pada halaman baru.'
        );
        $this->assertSame(
            1,
            $xpath->query('./w:pPr/w:keepNext', $heading)?->length,
            'Judul dokumentasi harus tetap bersama bidang foto.'
        );
        $this->assertSame(
            0,
            $xpath->query('./w:pPr/w:sectPr', $heading)?->length,
            'Pemisahan halaman tidak boleh menggunakan section break.'
        );

        $photoParagraph = $xpath->query(
            './following-sibling::w:p[1]',
            $heading
        )?->item(0);

        $this->assertNotNull($photoParagraph);
        $this->assertStringContainsString(
            '${foto_dokumentasi}',
            $photoParagraph->textContent
        );
    }

    public function test_documentation_montage_preserves_photo_ratio_and_visual_gap(): void
    {
        Storage::fake('local');

        $landscapePath = $this->storeColoredDocumentationImage(
            'landscape.jpg',
            1600,
            900,
            [210, 30, 30]
        );
        $portraitPath = $this->storeColoredDocumentationImage(
            'portrait.jpg',
            900,
            1600,
            [30, 60, 210]
        );
        $outputDirectory = storage_path(
            'app/private/documents/temporary'
        );
        $documentPath = (
            new LaporanPerjadinDocxGenerator(
                resource_path(
                    'documents/Template_Laporan_Perjadin.docx'
                ),
                $outputDirectory
            )
        )->generate([
            'nama_lengkap' => 'Pegawai Uji Montage',
            'nip' => '199001012020121001',
            'jabatan' => 'Penguji Dokumen',
            'pangkat_golongan' => 'Penata / III-c',
            'no_spt' => 'QA/MONTAGE/VIII/2026',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'kota_tujuan' => 'Makassar',
            'maksud_perjalanan' => 'Menguji tata letak foto.',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan kegiatan telah tercapai.',
            'tanggal_laporan' => '2026-08-20',
            'ttd_absolute_path' => public_path(
                'assets/images/logo.png'
            ),
            'foto_dokumentasi_paths' => [
                Storage::disk('local')->path($landscapePath),
                Storage::disk('local')->path($portraitPath),
            ],
        ]);

        try {
            $montage = $this->documentationMontageFromDocx(
                $documentPath,
                1800,
                2460
            );

            try {
                $redBounds = $this->colorBounds(
                    $montage,
                    fn (array $color): bool =>
                    $color['red'] >= 170
                        && $color['green'] <= 90
                        && $color['blue'] <= 90
                );
                $blueBounds = $this->colorBounds(
                    $montage,
                    fn (array $color): bool =>
                    $color['blue'] >= 170
                        && $color['red'] <= 90
                        && $color['green'] <= 120
                );

                $this->assertNotNull($redBounds);
                $this->assertNotNull($blueBounds);
                $this->assertEqualsWithDelta(
                    1600 / 900,
                    $redBounds['width'] / $redBounds['height'],
                    0.03
                );
                $this->assertEqualsWithDelta(
                    900 / 1600,
                    $blueBounds['width'] / $blueBounds['height'],
                    0.03
                );
                $this->assertLessThanOrEqual(
                    1200,
                    max($redBounds['width'], $redBounds['height'])
                );
                $this->assertLessThanOrEqual(
                    1200,
                    max($blueBounds['width'], $blueBounds['height'])
                );

                $visualGap = $blueBounds['top']
                    - $redBounds['bottom']
                    - 1;

                $this->assertGreaterThanOrEqual(58, $visualGap);
                $this->assertLessThanOrEqual(72, $visualGap);
            } finally {
                imagedestroy($montage);
            }
        } finally {
            if (is_file($documentPath)) {
                @unlink($documentPath);
            }
        }
    }

    public function test_ambiguous_white_padding_uses_uncropped_fallback(): void
    {
        $image = imagecreatetruecolor(1200, 1200);
        $this->assertInstanceOf(\GdImage::class, $image);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $generator = new LaporanPerjadinDocxGenerator(
            resource_path('documents/Template_Laporan_Perjadin.docx'),
            storage_path('app/private/documents/temporary')
        );
        $method = new \ReflectionMethod(
            LaporanPerjadinDocxGenerator::class,
            'documentationContentBounds'
        );

        try {
            $bounds = $method->invoke($generator, $image);
        } finally {
            imagedestroy($image);
        }

        $this->assertSame([
            'x' => 0,
            'y' => 0,
            'width' => 1200,
            'height' => 1200,
        ], $bounds);
    }

    public function test_owner_can_render_report_pdf_with_two_documentation_photos(): void
    {
        $this->skipWhenLibreOfficeIsUnavailable();
        Storage::fake('local');

        $owner = User::factory()->create();
        $signaturePath = 'signatures/report-documentation-test.png';
        Storage::disk('local')->put(
            $signaturePath,
            file_get_contents(
                public_path('assets/images/logo.png')
            )
        );
        $owner->update(['ttd_path' => $signaturePath]);

        $travel = $this->createTravel($owner);
        $report = $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
            'tanggal_laporan' => '2026-08-20',
        ]);
        $imageStorage = app(
            ReportDocumentationImageStorage::class
        );

        foreach ([
            UploadedFile::fake()->image('satu.jpg', 1600, 900),
            UploadedFile::fake()->image('dua.png', 900, 1600),
        ] as $index => $photo) {
            $report->dokumentasi()->create([
                'path' => $imageStorage->store($photo),
                'urutan' => $index + 1,
            ]);
        }

        $this->actingAs($owner)
            ->get(
                route(
                    'documents.laporan-perjadin',
                    ['id' => $travel->id]
                )
            )
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/pdf'
            );
    }

    private function storeColoredDocumentationImage(
        string $name,
        int $width,
        int $height,
        array $color
    ): string {
        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'sim_pd_photo_'
        );

        if ($temporaryPath === false) {
            $this->fail('Gagal menyiapkan gambar pengujian.');
        }

        $image = imagecreatetruecolor($width, $height);

        if (! $image instanceof \GdImage) {
            @unlink($temporaryPath);
            $this->fail('Gagal membuat gambar pengujian.');
        }

        try {
            $fill = imagecolorallocate(
                $image,
                $color[0],
                $color[1],
                $color[2]
            );
            imagefill($image, 0, 0, $fill);
            $this->assertTrue(
                imagejpeg($image, $temporaryPath, 95)
            );
        } finally {
            imagedestroy($image);
        }

        try {
            return app(
                ReportDocumentationImageStorage::class
            )->store(
                new UploadedFile(
                    $temporaryPath,
                    $name,
                    'image/jpeg',
                    null,
                    true
                )
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function documentationMontageFromDocx(
        string $documentPath,
        int $expectedWidth,
        int $expectedHeight
    ): \GdImage {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($documentPath) === true);

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);

                if (
                    ! is_string($name)
                    || ! str_starts_with($name, 'word/media/')
                ) {
                    continue;
                }

                $contents = $archive->getFromIndex($index);

                if (! is_string($contents)) {
                    continue;
                }

                $info = @getimagesizefromstring($contents);

                if (
                    ! is_array($info)
                    || $info[0] !== $expectedWidth
                    || $info[1] !== $expectedHeight
                ) {
                    continue;
                }

                $image = @imagecreatefromstring($contents);

                if ($image instanceof \GdImage) {
                    return $image;
                }
            }
        } finally {
            $archive->close();
        }

        $this->fail('Montage dokumentasi tidak ditemukan dalam DOCX.');
    }

    /**
     * @param  callable(array{red: int, green: int, blue: int}): bool  $matches
     * @return array{left: int, top: int, right: int, bottom: int, width: int, height: int}|null
     */
    private function colorBounds(
        \GdImage $image,
        callable $matches
    ): ?array {
        $left = imagesx($image);
        $top = imagesy($image);
        $right = -1;
        $bottom = -1;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $color = imagecolorsforindex(
                    $image,
                    imagecolorat($image, $x, $y)
                );

                if (! $matches($color)) {
                    continue;
                }

                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
            }
        }

        if ($right < $left || $bottom < $top) {
            return null;
        }

        return [
            'left' => $left,
            'top' => $top,
            'right' => $right,
            'bottom' => $bottom,
            'width' => $right - $left + 1,
            'height' => $bottom - $top + 1,
        ];
    }

    private function createTravel(User $owner): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'REPORT/DOC/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/REPORT/DOC/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'user_id' => $owner->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 4610000,
            'status' => PerjalananDinas::STATUS_READY,
        ]);
    }

    private function skipWhenLibreOfficeIsUnavailable(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }
    }
}
