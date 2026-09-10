<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\SptSrikandiWorkflow;
use App\Models\User;
use App\Services\Documents\TravelPdfDocumentService;
use App\Support\SptTemplateVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SptSrikandiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_officer_can_archive_upload_preview_and_publish_official_spt(): void
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

        $this->actingAs($officer)->post(route('travel-orders.store'), [
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
        ])->assertSessionHasNoErrors();

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame(PerjalananDinas::STATUS_DRAFT, $travel->status);
        $this->actingAs($employee)->get(route('dashboard.user'))
            ->assertDontSee($travel->spt_internal_reference, false);

        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\nstartxref\n0\n%%EOF\n";
        Storage::disk('local')->put('testing/draft.pdf', $pdf);
        $documentService = Mockery::mock(TravelPdfDocumentService::class);
        $documentService->shouldReceive('suratTugas')
            ->once()
            ->andReturn(Storage::disk('local')->path('testing/draft.pdf'));
        $this->app->instance(TravelPdfDocumentService::class, $documentService);

        $this->actingAs($officer)->post(route('spt-srikandi.mark-sent', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertSessionHasNoErrors();

        $workflow = SptSrikandiWorkflow::query()->firstOrFail();
        $this->assertSame(SptSrikandiWorkflow::STATUS_WAITING, $workflow->status);
        Storage::disk('local')->assertExists($workflow->draft_pdf_path);
        $this->actingAs($officer)->get(route('spt-srikandi.show', [
            'sptGroupId' => $travel->spt_group_id,
        ]))
            ->assertOk()
            ->assertSee('name="official_pdf"', false)
            ->assertDontSee('name="external_number"', false);
        $this->actingAs($officer)->get(route('travel-orders.edit', [
            'sptGroupId' => $travel->spt_group_id,
        ]))->assertRedirect(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]));

        $official = UploadedFile::fake()->createWithContent('SPT-Srikandi.pdf', $pdf);
        $this->actingAs($officer)->post(route('spt-srikandi.upload', [
            'sptGroupId' => $travel->spt_group_id,
        ]), [
            'official_pdf' => $official,
        ])->assertSessionHasNoErrors();

        $workflow->refresh();
        $travel->refresh();
        $this->assertSame(SptSrikandiWorkflow::STATUS_UPLOADED, $workflow->status);
        $this->assertSame(PerjalananDinas::STATUS_DRAFT, $travel->status);
        $this->assertNull($workflow->external_number);
        $this->assertNull($travel->spt_external_number);
        Storage::disk('local')->assertExists($workflow->official_pdf_path);
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
            ->assertSee($travel->spt_internal_reference, false);
        $this->actingAs($employee)->get(route('documents.surat-tugas', ['id' => $travel->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_non_officer_cannot_open_srikandi_management(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get(route('spt-srikandi.index'))->assertForbidden();
    }
}
