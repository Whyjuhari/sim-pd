<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\User;
use App\Repositories\PerjalananDinasRepository;
use App\Services\Documents\SuratTugasDocxGenerator;
use App\Services\Documents\TravelPdfDocumentService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DocumentGenerationTest extends TestCase
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

    public function test_approved_travel_is_rendered_as_pdf_from_official_spreadsheet_template(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }

        [, $employee, $travel] = $this->documentFixture();

        $response = $this->actingAs($employee)->get(
            route('documents.perjadin', ['id' => $travel->id])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_officer_can_render_surat_tugas_as_pdf_from_docx_template(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }

        [$officer, , $travel] = $this->documentFixture();

        $response = $this->actingAs($officer)->get(
            route('documents.surat-tugas', ['id' => $travel->id])
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_prepared_surat_tugas_is_served_without_running_libreoffice_again(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }

        [$officer, , $travel] = $this->documentFixture();
        app(TravelPdfDocumentService::class)->suratTugas($travel);

        config()->set(
            'sim_pd.documents.libreoffice.binary',
            storage_path('framework/testing/libreoffice-should-not-run')
        );

        $this->actingAs($officer)
            ->get(route('documents.surat-tugas', ['id' => $travel->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_collective_surat_tugas_contains_every_employee_in_one_document(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employees = collect([
            ['nama_lengkap' => 'Andi Pegawai Pertama', 'nip' => '199001012020121001', 'pangkat_golongan' => 'Penata Muda / IIIa', 'jabatan' => 'Instruktur Pertama'],
            ['nama_lengkap' => 'Budi Pegawai Kedua', 'nip' => '199102022020121002', 'pangkat_golongan' => 'Penata / IIIc', 'jabatan' => 'Analis Kepegawaian'],
            ['nama_lengkap' => 'Citra Pegawai Ketiga', 'nip' => '199203032020122003', 'pangkat_golongan' => 'Penata Tingkat I / IIId', 'jabatan' => 'Pengelola Program'],
        ])->map(fn (array $attributes) => User::factory()->create($attributes));
        $groupId = (string) Str::uuid();

        $travels = $employees->map(fn (User $employee) => PerjalananDinas::query()->create([
            'spt_group_id' => $groupId,
            'no_spt' => 'KOLEKTIF/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas kolektif',
            'no_memo' => 'MEMO/KOLEKTIF/001',
            'perihal_memo' => 'Koordinasi kolektif',
            'tgl_memo' => '2026-08-01',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi bersama',
            'kota_tujuan' => 'Jakarta',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'status' => PerjalananDinas::STATUS_READY,
        ]));

        $data = app(PerjalananDinasRepository::class)->findSuratTugasGroup($travels->last()->id);
        $this->assertNotNull($data);
        $this->assertCount(3, $data['pegawai_list']);

        $documents = config('sim_pd.documents');
        $docxPath = (new SuratTugasDocxGenerator(
            $documents['templates']['surat_tugas'],
            $documents['temporary_dir'],
        ))->generate($data);
        $documentXml = $this->readDocxPart($docxPath, 'word/document.xml');
        $plainText = html_entity_decode(strip_tags($documentXml));

        foreach ($employees as $employee) {
            $this->assertStringContainsString($employee->nama_lengkap, $plainText);
            $this->assertStringContainsString($employee->nip, $plainText);
        }

        $this->assertStringNotContainsString('${nomor_pegawai}', $plainText);
        $this->assertStringNotContainsString('${nama_pegawai}', $plainText);

        $response = $this->actingAs($officer)->get(
            route('documents.surat-tugas', ['id' => $travels->get(1)->id])
        );
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_surat_tugas_closing_block_is_kept_together_in_the_template(): void
    {
        $documentXml = $this->readDocxPart(
            config('sim_pd.documents.templates.surat_tugas'),
            'word/document.xml',
        );

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($documentXml));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');

        $closingTables = $xpath->query(
            '/w:document/w:body/w:tbl[.//w:t[contains(., "Demikian Surat Tugas ini dibuat")]]'
        );
        $this->assertNotFalse($closingTables);
        $this->assertSame(1, $closingTables->length);

        $closingTable = $closingTables->item(0);
        $this->assertNotNull($closingTable);

        $closingText = $closingTable->textContent;
        $this->assertSame(1, substr_count($closingText, 'Demikian Surat Tugas ini dibuat'));
        $this->assertSame(1, substr_count($closingText, '${tanggal_surat}'));
        $this->assertSame(1, substr_count($closingText, '${ttd}'));
        $this->assertSame(1, substr_count($closingText, 'Ashari Arifuddin, S.T., M.M'));

        $outerRows = $xpath->query('./w:tr', $closingTable);
        $this->assertNotFalse($outerRows);
        $this->assertSame(1, $outerRows->length);

        foreach ($outerRows as $row) {
            $this->assertSame(1, $xpath->query('./w:trPr/w:cantSplit', $row)->length);
        }

        $closingLayoutTables = $xpath->query(
            './w:tr/w:tc/w:tbl[.//w:t[contains(., "Demikian Surat Tugas ini dibuat")]]',
            $closingTable,
        );
        $this->assertNotFalse($closingLayoutTables);
        $this->assertSame(1, $closingLayoutTables->length);

        $closingLayoutRows = $xpath->query('./w:tr', $closingLayoutTables->item(0));
        $this->assertNotFalse($closingLayoutRows);
        $this->assertSame(2, $closingLayoutRows->length);

        foreach (['Demikian Surat Tugas ini dibuat', 'Pangkep,', '${ttd}'] as $text) {
            $keptParagraphs = $xpath->query(
                './/w:p[.//w:t[contains(., "'.$text.'")]]/w:pPr/w:keepNext[not(@w:val) or @w:val != "0"]',
                $closingTable,
            );
            $this->assertNotFalse($keptParagraphs);
            $this->assertSame(1, $keptParagraphs->length, "Paragraf {$text} harus memakai keepNext.");
        }

        $finalNameKeepNext = $xpath->query(
            './/w:p[.//w:t[contains(., "Ashari Arifuddin")]]/w:pPr/w:keepNext[not(@w:val) or @w:val != "0"]',
            $closingTable,
        );
        $this->assertNotFalse($finalNameKeepNext);
        $this->assertSame(0, $finalNameKeepNext->length);
        $this->assertSame(0, $xpath->query('.//wp:anchor', $closingTable)->length);

        $travelTables = $xpath->query(
            './following-sibling::*[1][self::w:tbl[.//w:t[contains(., "Berangkat dari")]]]',
            $closingTable,
        );
        $this->assertNotFalse($travelTables);
        $this->assertSame(1, $travelTables->length);

        $travelTable = $travelTables->item(0);
        $this->assertNotNull($travelTable);

        $travelRows = $xpath->query('./w:tr', $travelTable);
        $this->assertNotFalse($travelRows);
        $this->assertSame(8, $travelRows->length);

        foreach ($travelRows as $row) {
            $this->assertSame(1, $xpath->query('./w:trPr/w:cantSplit', $row)->length);
        }

        $travelParagraphs = $xpath->query('.//w:p', $travelTable);
        $this->assertNotFalse($travelParagraphs);
        $this->assertGreaterThan(1, $travelParagraphs->length);
        $this->assertSame(
            $travelParagraphs->length,
            $xpath->query('.//w:p[w:pPr/w:keepLines]', $travelTable)->length,
        );
        $this->assertSame(
            $travelParagraphs->length - 1,
            $xpath->query(
                './/w:p[w:pPr/w:keepNext[not(@w:val) or @w:val != "0"]]',
                $travelTable,
            )->length,
        );

        $lastTravelParagraph = $travelParagraphs->item($travelParagraphs->length - 1);
        $this->assertNotNull($lastTravelParagraph);
        $this->assertSame(
            0,
            $xpath->query('./w:pPr/w:keepNext', $lastTravelParagraph)->length,
        );
    }

    private function documentFixture(): array
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create([
            'nama_lengkap' => 'Pegawai Dokumen',
            'nip' => '199001012020121001',
        ]);
        MasterTarif::query()->create(['kota_tujuan' => 'Jakarta', 'uang_saku_per_hari' => 370000]);

        $travel = PerjalananDinas::query()->create([
            'no_spt' => 'DOC/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Jakarta',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 4610000,
            'biaya_hotel_real' => 500000,
            'biaya_tiket_real' => 1800000,
            'biaya_hotel_approved' => 500000,
            'total_cair' => 3410000,
            'status' => PerjalananDinas::STATUS_APPROVED,
        ]);

        return [$officer, $employee, $travel];
    }

    private function readDocxPart(string $path, string $part): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $contents = $zip->getFromName($part);
        $zip->close();
        $this->assertIsString($contents);

        return $contents;
    }
}
