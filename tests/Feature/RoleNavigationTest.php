<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_documents_share_one_inline_preview_panel_and_completed_card_opens_final_document(): void
    {
        Storage::fake('local');
        $employee = User::factory()->role(User::ROLE_USER)->create();
        Storage::disk('local')->put('signatures/employee-preview.png', 'signature');
        $employee->update(['ttd_path' => 'signatures/employee-preview.png']);

        $travel = $this->createTravel($employee, PerjalananDinas::STATUS_APPROVED);
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana.',
            'kesimpulan' => 'Tujuan tercapai.',
            'tanggal_laporan' => '2026-08-22',
        ]);

        $response = $this->actingAs($employee)->get(route('dashboard.user'));
        $response->assertOk()
            ->assertSee('data-primary-document-trigger="employee-final-document-trigger-'.$travel->id.'"', false)
            ->assertSee('data-document-label="Dokumen Perjalanan"', false)
            ->assertSee('data-document-label="Laporan Perjalanan Dinas"', false)
            ->assertSee('data-document-label="Surat Perintah Tugas"', false)
            ->assertSee('id="employee-document-preview-'.$travel->id.'"', false);

        $content = $response->getContent();
        $this->assertSame(3, substr_count($content, 'data-document-preview-trigger'));
        $this->assertSame(1, substr_count($content, 'class="document-preview-row d-none"'));
        $this->assertStringNotContainsString('target="_blank" href="'.route('documents.perjadin', ['id' => $travel->id]).'"', $content);
        $this->assertStringNotContainsString('target="_blank" href="'.route('documents.laporan-perjadin', ['id' => $travel->id]).'"', $content);
        $this->assertStringNotContainsString('target="_blank" href="'.route('documents.surat-tugas', ['id' => $travel->id]).'"', $content);
    }

    public function test_employee_report_preview_remains_locked_without_signature_and_final_document_requires_approval(): void
    {
        Storage::fake('local');
        $employee = User::factory()->role(User::ROLE_USER)->create();
        $travel = $this->createTravel($employee, PerjalananDinas::STATUS_PENDING);
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana.',
            'kesimpulan' => 'Tujuan tercapai.',
            'tanggal_laporan' => '2026-08-22',
        ]);

        $response = $this->actingAs($employee)->get(route('dashboard.user'));
        $response->assertOk()
            ->assertSee('title="Tanda tangan belum tersedia"', false)
            ->assertDontSee('data-document-label="Laporan Perjalanan Dinas"', false)
            ->assertDontSee('data-document-label="Dokumen Perjalanan"', false)
            ->assertSee('data-document-label="Surat Perintah Tugas"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'data-document-preview-trigger'));
    }

    public function test_employee_uses_one_workspace_link_across_dashboard_report_and_realization(): void
    {
        $employee = User::factory()->role(User::ROLE_USER)->create();
        $travel = $this->createTravel($employee);

        $this->assertWorkspaceNavigation(
            $this->actingAs($employee)->get(route('dashboard.user')),
            'Tugas Perjalanan',
            route('dashboard.user')
        );

        $this->assertWorkspaceNavigation(
            $this->get(route('travel-reports.edit', ['travel' => $travel->id])),
            'Tugas Perjalanan',
            route('dashboard.user')
        );

        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
            'tanggal_laporan' => '2026-08-22',
        ]);

        $this->assertWorkspaceNavigation(
            $this->get(route('realizations.show', ['id' => $travel->id])),
            'Tugas Perjalanan',
            route('dashboard.user')
        );
    }

    public function test_verifier_uses_one_workspace_link_across_dashboard_and_review_page(): void
    {
        $employee = User::factory()->role(User::ROLE_USER)->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee, PerjalananDinas::STATUS_PENDING);

        $this->assertWorkspaceNavigation(
            $this->actingAs($verifier)->get(route('dashboard.verifier')),
            'Verifikasi Realisasi',
            route('dashboard.verifier')
        );

        $this->assertWorkspaceNavigation(
            $this->get(route('verifications.show', ['id' => $travel->id])),
            'Verifikasi Realisasi',
            route('dashboard.verifier')
        );
    }

    public function test_other_roles_keep_the_dashboard_navigation(): void
    {
        $routes = [
            User::ROLE_ADMIN => 'dashboard.admin',
            User::ROLE_OFFICER => 'dashboard.officer',
            User::ROLE_PROGRAM => 'dashboard.program',
            User::ROLE_HEAD => 'dashboard.head',
        ];

        foreach ($routes as $role => $routeName) {
            $user = User::factory()->role($role)->create();
            $response = $this->actingAs($user)->get(route($routeName));

            $response->assertOk();
            $this->assertSame(2, substr_count($response->getContent(), '<span>Dashboard</span>'));
        }
    }

    private function assertWorkspaceNavigation(TestResponse $response, string $label, string $url): void
    {
        $response->assertOk();
        $content = $response->getContent();
        $normalizedContent = preg_replace('/\s+/', ' ', $content);

        $this->assertSame(2, substr_count($normalizedContent, "<span>{$label}</span>"));
        $this->assertSame(0, substr_count($normalizedContent, '<span>Dashboard</span>'));
        $this->assertSame(2, substr_count($normalizedContent, 'class="nav-link active" href="'.$url.'"'));
    }

    private function createTravel(User $employee, string $status = PerjalananDinas::STATUS_READY): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'NAV/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/NAV/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 4610000,
            'biaya_hotel_real' => 500000,
            'biaya_tiket_real' => 1800000,
            'uang_harian_per_hari_snapshot' => 370000,
            'batas_hotel_per_hari_snapshot' => 500000,
            'batas_transport_snapshot' => 2000000,
            'status' => $status,
        ]);
    }
}
