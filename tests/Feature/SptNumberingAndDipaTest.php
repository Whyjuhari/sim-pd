<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\DipaSetting;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\SptSrikandiWorkflow;
use App\Models\User;
use App\Repositories\PerjalananDinasRepository;
use App\Services\Documents\SuratTugasDocxGenerator;
use App\Support\SptTemplateVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SptNumberingAndDipaTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_defaults_to_external_number_parameter_and_lists_four_variants(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();

        $selection = $this->actingAs($officer)->get(route('travel-orders.create'));
        $selection->assertOk();
        foreach (SptTemplateVariant::all() as $variant) {
            $selection->assertSee($variant['label'], false);
        }

        $form = $this->actingAs($officer)->get(route('travel-orders.create', [
            'template' => SptTemplateVariant::token(SptTemplateVariant::REGULATIONS),
        ]));

        $form->assertOk()
            ->assertSee('value="external_parameter"', false)
            ->assertSee('value="${nomor_naskah}"', false)
            ->assertSee('name="spt_template_variant"', false)
            ->assertSee('value="regulations"', false)
            ->assertDontSee('name="no_memo"', false);
    }

    public function test_external_number_creates_hidden_srikandi_draft(): void
    {
        [$officer, $employee] = $this->actors();
        $secondEmployee = User::factory()->create();

        $response = $this->actingAs($officer)->post(route('travel-orders.store'), $this->payload($employee, [
            'user_ids' => [$employee->id, $secondEmployee->id],
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_EXTERNAL,
            'spt_template_variant' => SptTemplateVariant::REGULATIONS,
        ]))->assertSessionHasNoErrors();

        $travel = PerjalananDinas::query()->firstOrFail();
        $response->assertRedirect(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]));
        $groupTravels = PerjalananDinas::query()->where('spt_group_id', $travel->spt_group_id)->get();
        $this->assertCount(2, $groupTravels);
        $this->assertTrue($groupTravels->every(fn (PerjalananDinas $item): bool =>
            $item->no_spt === PerjalananDinas::NUMBER_PLACEHOLDER
            && $item->status === PerjalananDinas::STATUS_DRAFT
        ));
        $this->assertSame(PerjalananDinas::NUMBER_PLACEHOLDER, $travel->no_spt);
        $this->assertSame(PerjalananDinas::STATUS_DRAFT, $travel->status);
        $this->assertMatchesRegularExpression('/^REF-SPT\/2026\/[A-F0-9]{8}$/', $travel->spt_internal_reference);
        $this->assertSame($travel->spt_internal_reference, $travel->sptOperationalReference());
        $this->assertSame(PerjalananDinas::NUMBER_PLACEHOLDER, $travel->sptDocumentNumber());
        $this->assertFalse(Gate::forUser($employee)->allows('printSuratTugas', $travel));
        $this->assertFalse(Route::has('travel-orders.finalize-number'));
        $this->assertTrue(Route::has('travel-orders.record-srikandi-number'));
        $this->assertDatabaseHas('spt_srikandi_workflows', [
            'spt_group_id' => $travel->spt_group_id,
            'status' => SptSrikandiWorkflow::STATUS_DRAFT,
        ]);
        $documentData = app(PerjalananDinasRepository::class)->findForDocument($travel->id);
        $this->assertSame($travel->spt_internal_reference, $documentData['no_spt']);
        $this->assertSame(PerjalananDinas::NUMBER_PLACEHOLDER, $documentData['spt_document_number']);

        $this->actingAs($employee)
            ->get(route('dashboard.user'))
            ->assertOk()
            ->assertDontSee($travel->spt_internal_reference, false);

        $this->actingAs($officer)->post(route('travel-orders.record-srikandi-number', [
            'sptGroupId' => $travel->spt_group_id,
        ]), ['spt_external_number' => '2.23/FINAL/IX/2026'])
            ->assertSessionHasErrors('spt_external_number');
    }

    public function test_manual_number_keeps_legacy_ready_flow(): void
    {
        [$officer, $employee] = $this->actors();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->payload($employee, [
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_MANUAL,
            'no_spt' => 'MANUAL/001/2026',
            'spt_template_variant' => SptTemplateVariant::REGULATIONS_MEMO,
            'no_memo' => 'MEMO/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-09-01',
        ]))->assertSessionHasNoErrors();

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame('MANUAL/001/2026', $travel->no_spt);
        $this->assertSame(PerjalananDinas::NUMBER_MODE_MANUAL, $travel->spt_number_mode);
        $this->assertSame(PerjalananDinas::STATUS_READY, $travel->status);
        $this->assertDatabaseMissing('spt_srikandi_workflows', [
            'spt_group_id' => $travel->spt_group_id,
        ]);
    }

    public function test_dipa_variant_requires_setting_and_keeps_snapshot(): void
    {
        [$officer, $employee] = $this->actors();
        $payload = $this->payload($employee, [
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_EXTERNAL,
            'spt_template_variant' => SptTemplateVariant::REGULATIONS_DIPA,
        ]);

        $this->actingAs($officer)->post(route('travel-orders.store'), $payload)
            ->assertSessionHasErrors('tgl_berangkat');

        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $this->actingAs($program)->put(route('program.dipa.update', ['fiscalYear' => 2026]), [
            'document_number' => 'SP DIPA-026.13.2.352630/2026',
            'document_date' => '2025-12-02',
        ])->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('travel-orders.store'), $payload)
            ->assertSessionHasNoErrors();

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame(2026, $travel->dipa_fiscal_year_snapshot);
        $this->assertSame('SP DIPA-026.13.2.352630/2026', $travel->dipa_number_snapshot);
        $this->assertSame('2025-12-02', $travel->dipa_date_snapshot->format('Y-m-d'));
        $this->assertNull($travel->no_memo);
        $this->assertNull($travel->perihal_memo);

        DipaSetting::query()->where('fiscal_year', 2026)->update([
            'document_number' => 'SP DIPA-BARU/2026',
        ]);
        $this->assertSame(
            'SP DIPA-026.13.2.352630/2026',
            $travel->fresh()->dipa_number_snapshot
        );
    }

    public function test_duplicate_srikandi_number_and_non_officer_recording_are_rejected(): void
    {
        [$officer, $employee] = $this->actors();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->payload($employee, [
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_EXTERNAL,
            'spt_template_variant' => SptTemplateVariant::REGULATIONS,
        ]));
        $travel = PerjalananDinas::query()->firstOrFail();

        PerjalananDinas::query()->create([
            ...$travel->getAttributes(),
            'id' => null,
            'spt_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'spt_internal_reference' => 'REF-SPT/2026/BBBBBBBB',
            'no_spt' => 'DUPLIKAT/2026',
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_MANUAL,
            'status' => PerjalananDinas::STATUS_READY,
        ]);

        $this->actingAs($employee)->post(route('travel-orders.record-srikandi-number', [
            'sptGroupId' => $travel->spt_group_id,
        ]), ['spt_external_number' => 'BARU/2026'])->assertForbidden();

        $this->actingAs($officer)->post(route('travel-orders.record-srikandi-number', [
            'sptGroupId' => $travel->spt_group_id,
        ]), ['spt_external_number' => 'DUPLIKAT/2026'])->assertSessionHasErrors('spt_external_number');
    }

    public function test_built_in_templates_have_expected_optional_sections_and_original_hash_is_unchanged(): void
    {
        $this->assertSame(
            '4fbf2443310e4f951c9bcffa93468140f2992244788f99af40d4e8cb993b89fc',
            hash_file('sha256', resource_path('documents/Template_SPT_Perjadin_COLLECTIVE_V5.docx'))
        );

        $expectedBasisCounts = [
            SptTemplateVariant::REGULATIONS => 3,
            SptTemplateVariant::REGULATIONS_MEMO => 4,
            SptTemplateVariant::REGULATIONS_DIPA => 4,
            SptTemplateVariant::REGULATIONS_MEMO_DIPA => 5,
        ];

        foreach (SptTemplateVariant::all() as $key => $variant) {
            $text = $this->documentXmlText(SptTemplateVariant::path($key));
            $this->assertStringContainsString('${nomor_surat}', $text, $key);
            $this->assertStringContainsString('${tahun_anggaran}', $text, $key);
            $this->assertSame($variant['uses_memo'], str_contains($text, '${nomor_memo}'), $key);
            $this->assertSame($variant['uses_dipa'], str_contains($text, '${nomor_dipa}'), $key);
            $this->assertSame($variant['uses_dipa'], str_contains($text, '${tanggal_dipa}'), $key);
            $this->assertSame($expectedBasisCounts[$key], $this->numberedBasisParagraphCount(SptTemplateVariant::path($key)), $key);
            $this->assertSenderSignatureAnchored(SptTemplateVariant::path($key), $key);
        }
    }

    public function test_generator_preserves_external_placeholder_after_srikandi_number_is_recorded(): void
    {
        $output = storage_path('framework/testing/spt-numbering');
        if (! is_dir($output)) {
            mkdir($output, 0775, true);
        }
        $generator = new SuratTugasDocxGenerator(
            SptTemplateVariant::path(SptTemplateVariant::REGULATIONS),
            $output
        );
        $base = [
            'pegawai_list' => [[
                'nama_lengkap' => 'Pegawai Uji',
                'nip' => '199001012020011001',
                'pangkat_golongan' => 'III/a',
                'jabatan' => 'Analis',
            ]],
            'menimbang' => 'pengujian dokumen',
            'maksud_perjalanan' => 'Melaksanakan pengujian',
            'kota_tujuan' => 'Makassar',
            'tgl_berangkat' => '2026-09-10',
            'tgl_kembali' => '2026-09-11',
            'lama_hari' => 2,
            'akun_anggaran' => '524111',
        ];

        $beforeRecordingPath = $generator->generate([
            ...$base,
            'no_spt' => 'REF-SPT/2026/AAAAAAAA',
            'spt_document_number' => PerjalananDinas::NUMBER_PLACEHOLDER,
        ]);
        $afterRecordingPath = $generator->generate([
            ...$base,
            'no_spt' => '2.23/FINAL/IX/2026',
            'spt_document_number' => PerjalananDinas::NUMBER_PLACEHOLDER,
        ]);

        try {
            $this->assertStringContainsString(PerjalananDinas::NUMBER_PLACEHOLDER, $this->documentXmlText($beforeRecordingPath));
            $afterRecordingText = $this->documentXmlText($afterRecordingPath);
            $this->assertStringContainsString(PerjalananDinas::NUMBER_PLACEHOLDER, $afterRecordingText);
            $this->assertStringNotContainsString('2.23/FINAL/IX/2026', $afterRecordingText);
        } finally {
            @unlink($beforeRecordingPath);
            @unlink($afterRecordingPath);
        }
    }

    /** @return array{0: User, 1: User} */
    private function actors(): array
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $province = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->firstOrCreate(
            ['kota_tujuan' => 'Makassar'],
            ['province_id' => $province->id, 'uang_saku_per_hari' => 0]
        );

        return [$officer, $employee];
    }

    /** @return array<string, mixed> */
    private function payload(User $employee, array $overrides = []): array
    {
        return [
            'user_ids' => [$employee->id],
            'menimbang' => 'Kebutuhan dinas',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-09-10',
            'tgl_kembali' => '2026-09-11',
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => BudgetAccount::query()->where('is_active', true)->value('code'),
            ...$overrides,
        ];
    }

    private function documentXmlText(string $path): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, $path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)));
    }

    private function numberedBasisParagraphCount(string $path): int
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, $path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $cells = $xpath->query('//w:tc[.//w:t[contains(., "Peraturan Menteri Ketenagakerjaan Nomor 20 Tahun 2024")]]');
        $this->assertNotFalse($cells);
        $this->assertGreaterThan(0, $cells->length);

        $paragraphs = $xpath->query('./w:p[w:pPr/w:numPr]', $cells->item(0));

        return $paragraphs === false ? 0 : $paragraphs->length;
    }

    private function assertSenderSignatureAnchored(string $path, string $label): void
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, $path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), $label);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $anchored = $xpath->query(
            '/w:document/w:body/w:tbl[.//w:t[contains(., "VI.")]][.//w:t[contains(., "Catatan")]]'
            .'/w:tr[1]/w:tc[2][.//w:t[contains(., "Kepala")]]'
            .'//w:p[.//w:t[contains(., "${ttd_pengirim}")]]'
        );
        $this->assertNotFalse($anchored);
        $this->assertSame(1, $anchored->length, $label);
        $this->assertSame(
            0,
            $xpath->query('/w:document/w:body/w:p//w:t[contains(., "${ttd_pengirim}")]')->length,
            $label,
        );
        $this->assertSame(
            0,
            $xpath->query('//w:txbxContent//w:t[contains(., "${ttd_pengirim}")]')->length,
            $label,
        );
        $this->assertSame(
            0,
            $xpath->query(
                '/w:document/w:body/w:tbl[.//w:t[contains(., "VI.")]][.//w:t[contains(., "Catatan")]]'
                .'/w:tr[6]//w:t[contains(., "${ttd_pengirim}")]'
            )->length,
            $label,
        );
    }
}
