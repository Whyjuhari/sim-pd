<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\SptSrikandiVersion;
use App\Models\SptSrikandiWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficerSptUiTest extends TestCase
{
    use RefreshDatabase;

    private function letter(?string $workflowStatus = null): PerjalananDinas
    {
        $employee = User::factory()->create();
        $group = (string) Str::uuid();
        $travel = PerjalananDinas::query()->create([
            'spt_group_id' => $group,
            'spt_internal_reference' => 'REF-SPT/2026/'.strtoupper(substr($group, 0, 8)),
            'spt_number_mode' => $workflowStatus ? PerjalananDinas::NUMBER_MODE_EXTERNAL : PerjalananDinas::NUMBER_MODE_MANUAL,
            'no_spt' => $workflowStatus ? PerjalananDinas::NUMBER_PLACEHOLDER : 'MANUAL/'.substr($group, 0, 8),
            'menimbang' => 'Kebutuhan pelaksanaan tugas.',
            'no_memo' => null,
            'perihal_memo' => null,
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Koordinasi kegiatan.',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-09-20',
            'tgl_kembali' => '2026-09-22',
            'lama_hari' => 3,
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 1110000,
            'status' => $workflowStatus && $workflowStatus !== SptSrikandiWorkflow::STATUS_PUBLISHED
                ? PerjalananDinas::STATUS_DRAFT : PerjalananDinas::STATUS_READY,
        ]);
        if ($workflowStatus) {
            SptSrikandiWorkflow::query()->create(['spt_group_id' => $group, 'status' => $workflowStatus]);
        }

        return $travel;
    }

    public function test_dashboard_summary_counts_groups_and_process_list_stays_scoped_to_unfinished_workflows(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $pending = collect([
            SptSrikandiWorkflow::STATUS_DRAFT, SptSrikandiWorkflow::STATUS_WAITING,
            SptSrikandiWorkflow::STATUS_REVISION, SptSrikandiWorkflow::STATUS_UPLOADED,
        ])->map(fn ($status) => $this->letter($status));
        $published = $this->letter(SptSrikandiWorkflow::STATUS_PUBLISHED);
        $manual = $this->letter();
        $legacy = $this->letter();
        $legacy->update(['spt_number_mode' => PerjalananDinas::NUMBER_MODE_EXTERNAL, 'no_spt' => PerjalananDinas::NUMBER_PLACEHOLDER]);
        $pendingVerification = $this->letter();
        $pendingVerification->update(['status' => PerjalananDinas::STATUS_PENDING]);
        $rejected = $this->letter();
        $rejected->update(['status' => PerjalananDinas::STATUS_REJECTED]);
        $approved = $this->letter();
        $approved->update(['status' => PerjalananDinas::STATUS_APPROVED]);
        $collectiveMember = $manual->replicate();
        $collectiveMember->user_id = User::factory()->create()->id;
        $collectiveMember->save();

        $dashboard = $this->actingAs($officer)->get(route('dashboard.officer'))->assertOk();
        $dashboard->assertViewHas('totalSpt', 10)
            ->assertViewHas('draftSptCount', 4)
            ->assertViewHas('runningSptCount', 3)
            ->assertSeeText('Total Surat Tugas')
            ->assertSeeText('SPT Sedang Diproses')
            ->assertSeeText('SPT Berjalan')
            ->assertSeeText('Dashboard')
            ->assertSeeText('Daftar Seluruh Surat Tugas')
            ->assertSeeText('Status SPT')
            ->assertDontSeeText('Jumlah Draft SPT')
            ->assertSee('data-live-filter-link', false)
            ->assertSee(route('dashboard.officer', ['status' => PerjalananDinas::STATUS_DRAFT]), false)
            ->assertSee(route('dashboard.officer', ['status' => PerjalananDinas::STATUS_READY]), false)
            ->assertDontSee('name="process_status"', false);
        $dashboardDom = new \DOMDocument();
        @$dashboardDom->loadHTML('<?xml encoding="UTF-8">'.$dashboard->getContent());
        $dashboardXpath = new \DOMXPath($dashboardDom);
        $this->assertSame(1, $dashboardXpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " metric-card ")][.//span[normalize-space(.)="Total Surat Tugas"]]')->length);
        $this->assertSame(0, $dashboardXpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " metric-card ")][.//span[normalize-space(.)="Total Surat Tugas"]]')->length);
        $this->assertSame(2, substr_count($dashboard->getContent(), 'data-live-filter-link'));
        $this->assertSame(10, $dashboardXpath->query('//table//a[normalize-space(.)="Detail"]')->length);
        $this->assertSame(0, $dashboardXpath->query('//table//a[normalize-space(.)="Kelola Proses"]')->length);
        $this->assertSame(6, $dashboardXpath->query('//table//button[@data-document-preview-trigger]')->length);
        $shown = $dashboard->viewData('sptGroups')->getCollection()->pluck('travel.id')->sort()->values()->all();
        $this->assertSame(
            $pending->concat([
                $published, $manual, $legacy, $pendingVerification, $rejected, $approved,
            ])->pluck('id')->sort()->values()->all(),
            $shown
        );

        $draftDashboard = $this->get(route('dashboard.officer', ['status' => PerjalananDinas::STATUS_DRAFT]))->assertOk();
        $this->assertSame(4, $draftDashboard->viewData('sptGroups')->total());
        $draftDom = new \DOMDocument();
        @$draftDom->loadHTML('<?xml encoding="UTF-8">'.$draftDashboard->getContent());
        $draftXpath = new \DOMXPath($draftDom);
        $this->assertSame(4, $draftXpath->query('//table//a[normalize-space(.)="Detail"]')->length);
        $this->assertSame(0, $draftXpath->query('//table//button[@data-document-preview-trigger]')->length);
        $this->assertSame(
            $pending->pluck('id')->sort()->values()->all(),
            $draftDashboard->viewData('sptGroups')->getCollection()->pluck('travel.id')->sort()->values()->all()
        );

        $runningDashboard = $this->get(route('dashboard.officer', ['status' => PerjalananDinas::STATUS_READY]))->assertOk();
        $this->assertSame(3, $runningDashboard->viewData('sptGroups')->total());
        $this->assertStringContainsString(
            'a[data-live-filter-link]',
            file_get_contents(resource_path('js/live-filters.js'))
        );

        $process = $this->get(route('spt-srikandi.index'))->assertOk();
        $process->assertDontSee('officer-process-tabs', false)->assertSee('Kelola SPT');
        $this->assertSame(4, $process->viewData('workflows')->total());
        $process->assertDontSee($published->spt_internal_reference, false)
            ->assertDontSee($manual->no_spt, false)->assertDontSee($legacy->spt_internal_reference, false);
        $filtered = $this->get(route('spt-srikandi.index', ['status' => SptSrikandiWorkflow::STATUS_WAITING]))->assertOk();
        $this->assertSame(1, $filtered->viewData('workflows')->total());
        $searched = $this->get(route('spt-srikandi.index', ['q' => $pending->first()->spt_internal_reference]))->assertOk();
        $this->assertSame(1, $searched->viewData('workflows')->total());
    }

    public function test_detail_has_no_duplicate_identity_actions_or_generic_send_confirmation(): void
    {
        Storage::fake('local');
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $travel = $this->letter(SptSrikandiWorkflow::STATUS_DRAFT);
        $response = $this->actingAs($officer)->get(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]))
            ->assertOk()->assertSee('Rincian SPT')->assertSee('Unduh Draft')
            ->assertDontSee('Informasi Surat Tugas')->assertDontSee('spt-process-timeline', false)
            ->assertDontSee('Memo Internal')->assertDontSee('Arsip File Word');
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//a[normalize-space(.)="Kembali"]')->length);
        $this->assertSame(1, $xpath->query('//a[normalize-space(.)="Edit SPT"]')->length);
        $this->assertSame(1, substr_count($response->getContent(), e($travel->pegawai->nama_lengkap)));
        $this->assertSame(1, $xpath->query('//form[contains(@action,"upload")]//input[@name="official_pdf"]')->length);
        $this->assertSame(0, $xpath->query('//details[contains(@class,"officer-spt-details") and @open]')->length);
    }

    public function test_published_details_return_to_dashboard_and_pending_details_keep_process_filters(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $published = $this->letter(SptSrikandiWorkflow::STATUS_PUBLISHED);
        $response = $this->actingAs($officer)->get(route('travel-orders.show', [
            'sptGroupId' => $published->spt_group_id, 'from' => 'process',
            'list' => ['q' => 'before publishing', 'status' => 'uploaded', 'page' => '2'],
        ]))->assertOk()->assertViewHas('detailBackRoute', route('dashboard.officer'))
            ->assertDontSee('name="official_pdf"', false);

        $pending = $this->letter(SptSrikandiWorkflow::STATUS_WAITING);
        $filters = ['q' => 'Makassar', 'status' => SptSrikandiWorkflow::STATUS_WAITING, 'page' => '2'];
        $this->get(route('travel-orders.show', ['sptGroupId' => $pending->spt_group_id, 'from' => 'process', 'list' => $filters]))
            ->assertOk()->assertViewHas('detailBackRoute', route('spt-srikandi.index', $filters));
        $manual = $this->letter();
        $this->get(route('travel-orders.show', ['sptGroupId' => $manual->spt_group_id]))
            ->assertOk()->assertViewHas('detailBackRoute', route('dashboard.officer'));
    }

    public function test_old_tab_links_still_lead_to_the_correct_list(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $this->actingAs($officer)->get(route('spt-srikandi.index', ['tab' => 'published', 'q' => 'Makassar']))
            ->assertRedirect(route('dashboard.officer', ['q' => 'Makassar']));
        $this->get(route('spt-srikandi.index', ['tab' => 'waiting']))
            ->assertRedirect(route('spt-srikandi.index', ['q' => '', 'status' => SptSrikandiWorkflow::STATUS_WAITING]));
        $this->get(route('dashboard.officer', ['process_status' => SptSrikandiWorkflow::STATUS_DRAFT]))
            ->assertRedirect(route('spt-srikandi.index', ['q' => '', 'status' => SptSrikandiWorkflow::STATUS_DRAFT]));
    }

    public function test_revision_details_are_open_and_have_one_edit_action(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $travel = $this->letter(SptSrikandiWorkflow::STATUS_REVISION);
        $response = $this->actingAs($officer)->get(route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), '> Edit SPT</a>'));
        $response->assertSee('class="card officer-spt-details"  open ', false);
    }

    public function test_upload_form_reappears_with_validation_error_instead_of_staying_hidden(): void
    {
        Storage::fake('local');
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $travel = $this->letter(SptSrikandiWorkflow::STATUS_DRAFT);
        $route = route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]);

        $hidden = $this->actingAs($officer)->get($route)->assertOk();
        $this->assertTrue($this->uploadFormHasClass($hidden->getContent(), 'd-none'));

        $failed = $this->actingAs($officer)
            ->from($route)
            ->followingRedirects()
            ->post(route('spt-srikandi.upload', ['sptGroupId' => $travel->spt_group_id]), [
                'official_pdf' => UploadedFile::fake()->create('oversized.pdf', 11000, 'application/pdf'),
            ])
            ->assertOk();

        $this->assertFalse($this->uploadFormHasClass($failed->getContent(), 'd-none'));
    }

    public function test_upload_form_stays_visible_after_draft_download_and_refresh(): void
    {
        Storage::fake('local');
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $travel = $this->letter(SptSrikandiWorkflow::STATUS_DRAFT);
        $route = route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]);

        $before = $this->actingAs($officer)->get($route)->assertOk();
        $this->assertTrue($this->uploadFormHasClass($before->getContent(), 'd-none'));

        $workflow = SptSrikandiWorkflow::query()->where('spt_group_id', $travel->spt_group_id)->firstOrFail();
        $docx = $this->fakeConceptDocx();
        $path = 'spt-srikandi/'.$travel->spt_group_id.'/concepts/version-1.docx';
        Storage::disk('local')->put($path, $docx);
        SptSrikandiVersion::query()->create([
            'workflow_id' => $workflow->id,
            'version_number' => 1,
            'docx_path' => $path,
            'docx_original_name' => 'Konsep_SPT_V1.docx',
            'docx_size_bytes' => strlen($docx),
            'docx_sha256' => hash('sha256', $docx),
            'prepared_by' => $officer->id,
            'prepared_at' => now(),
        ]);

        $after = $this->actingAs($officer)->get($route)->assertOk();
        $this->assertFalse($this->uploadFormHasClass($after->getContent(), 'd-none'));
    }

    private function uploadFormHasClass(string $html, string $class): bool
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $form = (new \DOMXPath($dom))->query('//form[@data-spt-official-upload-form]')->item(0);

        return $form instanceof \DOMElement
            && in_array($class, preg_split('/\s+/', (string) $form->getAttribute('class')), true);
    }

    private function fakeConceptDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sim-pd-docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }
}
