<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\User;
use App\Repositories\PerjalananDinasRepository;
use App\Services\Documents\SuratTugasDocxGenerator;
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
