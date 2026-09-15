<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\SptSrikandiVersion;
use App\Models\SptSrikandiWorkflow;
use App\Models\User;
use App\Services\Documents\SptOfficialNumberExtractor;
use App\Support\SptTemplateVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SptSrikandiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_officer_can_prepare_revise_upload_preview_and_share_official_spt(): void
    {
        config(['sim_pd.documents.cache.prewarm_after_response' => false]);
        Storage::fake('local');

        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $province = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->firstOrCreate(
            ['kota_tujuan' => 'Makassar'],
            ['province_id' => $province->id, 'uang_saku_per_hari' => 0]
        );

        $payload = [
            'user_ids' => [$employee->id],
            'spt_number_mode' => PerjalananDinas::NUMBER_MODE_EXTERNAL,
            'spt_template_variant' => SptTemplateVariant::REGULATIONS,
            'menimbang' => 'Kebutuhan dinas',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-09-10',
            'tgl_kembali' => '2026-09-11',
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => BudgetAccount::query()->where('is_active', true)->value('code'),
        ];
        $this->actingAs($officer)->post(route('travel-orders.store'), $payload)
            ->assertSessionHasNoErrors();

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame(PerjalananDinas::STATUS_DRAFT, $travel->status);
        $this->actingAs($employee)->get(route('dashboard.user'))
            ->assertDontSee($travel->spt_internal_reference, false);
        $this->actingAs($officer)->get(route('travel-orders.show', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()
            ->assertSee('Rincian SPT')
            ->assertSee('Unduh Draft')
            ->assertSee('Saya sudah mengunggah Draft ke')
            ->assertDontSee('Nomor Srikandi');

        $this->actingAs($officer)->post(route('spt-srikandi.mark-sent', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertSessionHasErrors('confirmed_uploaded');

        $this->actingAs($officer)->get(route('spt-srikandi.concept-document', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()->assertDownload();

        $firstVersion = SptSrikandiVersion::query()->firstOrFail();
        Storage::disk('local')->assertExists($firstVersion->docx_path);
        $firstChecksum = $firstVersion->docx_sha256;
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path($firstVersion->docx_path)) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertIsString($documentXml);
        $this->assertStringContainsString(PerjalananDinas::NUMBER_PLACEHOLDER, $documentXml);
        $this->assertStringContainsString('${ttd}', $documentXml);
        $this->assertStringContainsString('${ttd_pengirim}', $documentXml);

        $this->actingAs($officer)->get(route('spt-srikandi.concept-document', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()->assertDownload();
        $this->assertSame(1, SptSrikandiVersion::query()->count());
        $this->assertSame($firstChecksum, SptSrikandiVersion::query()->firstOrFail()->docx_sha256);

        $this->actingAs($officer)->post(route('spt-srikandi.mark-sent', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            'confirmed_uploaded' => '1',
        ])->assertSessionHasNoErrors();

        $workflow = SptSrikandiWorkflow::query()->firstOrFail();
        $this->assertSame(SptSrikandiWorkflow::STATUS_WAITING, $workflow->status);
        $this->assertNotNull($firstVersion->fresh()->submitted_at);
        $this->actingAs($officer)->get(route('travel-orders.show', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()
            ->assertSee('Menunggu SPT Selesai')
            ->assertSee('name="official_pdf"', false)
            ->assertDontSee('name="external_number"', false);
        $this->actingAs($officer)->get(route('spt-srikandi.show', [
            'sptGroupId' => $travel->spt_group_id,
        ]))
            ->assertRedirect(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]));
        $this->actingAs($officer)->get(route('travel-orders.edit', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertRedirect(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]));

        $this->actingAs($officer)->post(route('spt-srikandi.revision', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            'revision_reason' => 'Tujuan perjalanan perlu diperbaiki.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(SptSrikandiWorkflow::STATUS_REVISION, $workflow->fresh()->status);
        $this->assertSame('Tujuan perjalanan perlu diperbaiki.', $firstVersion->fresh()->revision_reason);
        $this->actingAs($officer)->get(route('travel-orders.edit', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk();

        $this->actingAs($officer)->get(route('spt-srikandi.concept-document', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()->assertDownload();
        $this->assertSame(2, SptSrikandiVersion::query()->count());

        $this->actingAs($officer)->put(route('travel-orders.update', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            ...$payload,
            'maksud_perjalanan' => 'Melaksanakan koordinasi yang telah diperbaiki',
        ])->assertSessionHasNoErrors();
        $this->assertSame(SptSrikandiWorkflow::STATUS_DRAFT, $workflow->fresh()->status);
        $this->assertSame(1, SptSrikandiVersion::query()->count());

        $this->actingAs($officer)->get(route('spt-srikandi.concept-document', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()->assertDownload();
        $this->assertSame(2, SptSrikandiVersion::query()->count());
        $this->actingAs($officer)->post(route('spt-srikandi.mark-sent', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            'confirmed_uploaded' => '1',
        ])->assertSessionHasNoErrors();

        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\nstartxref\n0\n%%EOF\n";

        $official = UploadedFile::fake()->createWithContent('SPT-Srikandi.pdf', $pdf);
        $officialNumber = '2.23/5622/LP.00.05/IX/2026';
        $this->mock(SptOfficialNumberExtractor::class)
            ->shouldReceive('extract')
            ->once()
            ->andReturn($officialNumber);
        $this->actingAs($officer)->post(route('spt-srikandi.upload', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            'official_pdf' => $official,
        ])->assertSessionHasNoErrors();

        $workflow->refresh();
        $travel->refresh();
        $this->assertSame(SptSrikandiWorkflow::STATUS_UPLOADED, $workflow->status);
        $this->assertSame(PerjalananDinas::STATUS_DRAFT, $travel->status);
        $this->assertSame($officialNumber, $workflow->external_number);
        $this->assertSame($officialNumber, $travel->spt_external_number);
        $this->assertSame(PerjalananDinas::NUMBER_PLACEHOLDER, $travel->no_spt);
        $this->assertSame($officialNumber, $travel->sptOperationalReference());
        Storage::disk('local')->assertExists($workflow->official_pdf_path);
        $this->actingAs($officer)->get(route('travel-orders.show', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()
            ->assertSee('Nomor Naskah berhasil dibaca')
            ->assertSee($officialNumber, false)
            ->assertSee('Bagikan ke Pegawai');
        $this->actingAs($officer)->get(route('spt-srikandi.index', [
            'q' => $officialNumber,
        ]))->assertOk()->assertSee($officialNumber, false);
        $this->actingAs($officer)->get(route('spt-srikandi.document', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($employee)->get(route('documents.surat-tugas', ['id' => $travel->id]))
            ->assertNotFound();

        $this->actingAs($officer)->post(route('spt-srikandi.publish', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(SptSrikandiWorkflow::STATUS_PUBLISHED, $workflow->fresh()->status);
        $this->assertSame(PerjalananDinas::STATUS_READY, $travel->fresh()->status);
        $this->actingAs($employee)->get(route('dashboard.user'))
            ->assertOk()
            ->assertSee($officialNumber, false);
        $this->actingAs($employee)->get(route('documents.surat-tugas', ['id' => $travel->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_non_officer_cannot_open_srikandi_management(): void
    {
        $employee = User::factory()->create();
        $groupId = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($employee)->get(route('spt-srikandi.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('spt-srikandi.concept-document', [
            'sptGroupId' => $groupId,
        ]))->assertForbidden();
        $this->actingAs($employee)->post(route('spt-srikandi.revision', [
            'sptGroupId' => $groupId,
        ]), ['revision_reason' => 'Perlu diperbaiki'])->assertForbidden();
    }
}
