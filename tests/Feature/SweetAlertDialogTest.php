<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SweetAlertDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_success_message_is_exposed_as_escaped_toast_data(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $response = $this->actingAs($admin)
            ->withSession(['success' => 'Data <script>alert("x")</script> berhasil disimpan.'])
            ->get(route('dashboard.admin'));

        $response->assertOk();
        $response->assertSee('data-flash-success="Data &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; berhasil disimpan."', false);
        $response->assertDontSee('<div class="alert alert-success', false);
    }

    public function test_verifier_actions_keep_distinct_submitter_values_and_confirmations(): void
    {
        $employee = User::factory()->role(User::ROLE_USER)->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee, PerjalananDinas::STATUS_PENDING);

        $response = $this->actingAs($verifier)
            ->get(route('verifications.show', ['id' => $travel->id]));

        $response->assertOk();
        $response->assertSee('name="action" value="reject"', false);
        $response->assertSee('name="action" value="approve"', false);
        $response->assertSee('data-sim-confirm-title="Kembalikan untuk revisi?"', false);
        $response->assertSee('data-sim-confirm-title="Setujui realisasi?"', false);
    }

    public function test_all_risky_actions_use_declarative_dialogs_without_native_calls(): void
    {
        $viewFiles = [
            resource_path('views/employees/index.blade.php'),
            resource_path('views/employees/form.blade.php'),
            resource_path('views/program/budget.blade.php'),
            resource_path('views/program/daily-allowance-preview.blade.php'),
            resource_path('views/program/hotel-rate-preview.blade.php'),
            resource_path('views/program/ground-transport-preview.blade.php'),
            resource_path('views/travel/show.blade.php'),
            resource_path('views/travel/reports.blade.php'),
            resource_path('views/travel/realization.blade.php'),
            resource_path('views/travel/verification.blade.php'),
        ];

        $confirmationMarkup = implode("\n", array_map('file_get_contents', $viewFiles));
        $dialogSources = $confirmationMarkup
            ."\n".file_get_contents(resource_path('js/app.js'))
            ."\n".file_get_contents(resource_path('js/report-preview.js'));

        preg_match_all('/\bdata-sim-confirm(?=\s|>)/', $confirmationMarkup, $confirmationAttributes);

        $this->assertCount(13, $confirmationAttributes[0]);
        $this->assertStringContainsString('data-realization-preview', $confirmationMarkup);
        $this->assertStringContainsString("Swal.fire", $confirmationMarkup);
        $this->assertStringContainsString('data-existing-evidence', $confirmationMarkup);
        $this->assertStringContainsString('URL.createObjectURL(file)', $confirmationMarkup);
        $this->assertStringContainsString('URL.revokeObjectURL(url)', $confirmationMarkup);
        $this->assertStringContainsString('input[name="hapus_bukti[]"]', $confirmationMarkup);
        $this->assertDoesNotMatchRegularExpression('/\b(?:confirm|alert|prompt)\s*\(/i', $dialogSources);
        $this->assertStringContainsString('event.submitter', $dialogSources);
        $this->assertStringContainsString('form.requestSubmit(submitter)', $dialogSources);
    }

    public function test_report_uses_a_safe_bootstrap_preview_before_submission(): void
    {
        $reportView = file_get_contents(resource_path('views/travel/reports.blade.php'));
        $previewSource = file_get_contents(resource_path('js/report-preview.js'));

        $this->assertStringContainsString('data-report-preview-form', $reportView);
        $this->assertStringContainsString('data-report-form-section', $reportView);
        $this->assertStringContainsString('data-report-preview-section', $reportView);
        $this->assertStringContainsString('data-report-preview-trigger', $reportView);
        $this->assertStringContainsString('data-document-preview-trigger', $reportView);
        $this->assertStringContainsString('data-report-preview-review', $reportView);
        $this->assertStringContainsString('data-report-preview-confirm', $reportView);
        $this->assertStringNotContainsString('data-report-preview-modal="report-preview-modal"', $reportView);
        $this->assertStringNotContainsString('data-sim-confirm-title="Simpan laporan kegiatan?"', $reportView);

        $this->assertStringContainsString('showPreviewView', $previewSource);
        $this->assertStringContainsString('showFormView', $previewSource);
        $this->assertStringContainsString('data-report-form-section', $previewSource);
        $this->assertStringContainsString('form.checkValidity()', $previewSource);
        $this->assertStringContainsString('form.reportValidity()', $previewSource);
        $this->assertStringContainsString('form.requestSubmit()', $previewSource);
        $this->assertStringNotContainsString('Modal.getOrCreateInstance', $previewSource);
    }

    private function createTravel(User $employee, string $status): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'DIALOG/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/DIALOG/001',
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
