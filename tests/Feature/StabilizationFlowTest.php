<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StabilizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_password_must_be_changed_before_features_can_be_used(): void
    {
        $employee = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($employee)
            ->get(route('dashboard.user'))
            ->assertRedirect(route('profile.edit'));

        $this->post(route('profile.update'), [
            'nama_lengkap' => $employee->nama_lengkap,
            'pangkat' => $employee->pangkat_golongan,
            'current_password' => 'salah',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertSessionHasErrors('current_password');

        $this->post(route('profile.update'), [
            'nama_lengkap' => $employee->nama_lengkap,
            'pangkat' => $employee->pangkat_golongan,
            'current_password' => '123456',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertFalse($employee->force_password_change);
        $this->assertTrue(Hash::check('password-baru', $employee->password));
        $this->get(route('dashboard.user'))->assertOk();
    }

    public function test_rate_snapshots_and_hotel_limit_per_day_are_used_during_verification(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        MasterTarif::query()->create(['kota_tujuan' => 'Jakarta', 'uang_saku_per_hari' => 370000]);
        Setting::query()->insert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ]);

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->travelOrderData($employee))
            ->assertRedirect(route('dashboard.officer'));

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame('370000.00', $travel->uang_harian_per_hari_snapshot);
        $this->assertSame('500000.00', $travel->batas_hotel_per_hari_snapshot);
        $this->assertSame('2000000.00', $travel->batas_transport_snapshot);
        $this->assertSame($officer->id, $travel->created_by);

        MasterTarif::query()->where('kota_tujuan', 'Jakarta')->update(['uang_saku_per_hari' => 10000]);
        Setting::query()->where('nama_setting', 'batas_hotel')->update(['nilai_setting' => 100000]);
        Setting::query()->where('nama_setting', 'pagu_tiket')->update(['nilai_setting' => 200000]);

        $this->createReport($travel);
        $this->actingAs($employee)->post(route('realizations.store'), [
            'id' => $travel->id,
            'biaya_hotel' => 1400000,
            'biaya_tiket' => 1900000,
        ])->assertRedirect(route('dashboard.user'));

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'hotel_approved' => 1400000,
            'tiket_approved' => 1900000,
            'catatan' => 'Sesuai bukti.',
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_APPROVED, $travel->status);
        $this->assertSame('4410000.00', $travel->total_cair);
        $this->assertSame('1900000.00', $travel->biaya_tiket_real);
        $this->assertSame('1900000.00', $travel->biaya_tiket_approved);
    }

    public function test_rejected_realization_can_be_revised_without_unlocking_the_report(): void
    {
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->pendingTravel($employee);
        $this->createReport($travel);

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'reject',
            'catatan' => '',
        ])->assertSessionHasErrors('catatan');

        $this->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'reject',
            'catatan' => 'Nilai tiket tidak sesuai bukti.',
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_REJECTED, $travel->status);
        $this->assertSame('Nilai tiket tidak sesuai bukti.', $travel->catatan_verifikator);
        $this->assertSame('0.00', $travel->biaya_hotel_approved);
        $this->assertSame('0.00', $travel->biaya_tiket_approved);

        $this->actingAs($employee)
            ->get(route('travel-reports.edit', ['travel' => $travel->id]))
            ->assertForbidden();
        $this->get(route('realizations.show', ['id' => $travel->id]))
            ->assertOk()
            ->assertSeeText('Nilai tiket tidak sesuai bukti.');

        $this->post(route('realizations.store'), [
            'id' => $travel->id,
            'biaya_hotel' => 600000,
            'biaya_tiket' => 1200000,
        ])->assertRedirect(route('dashboard.user'));

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'hotel_approved' => 600000,
            'tiket_approved' => 1200000,
            'catatan' => 'Perbaikan diterima.',
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_APPROVED, $travel->status);
        $this->assertDatabaseCount('perjalanan_dinas_status_histories', 3);

        $this->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'hotel_approved' => 600000,
            'tiket_approved' => 1200000,
        ])->assertNotFound();
        $this->assertDatabaseCount('perjalanan_dinas_status_histories', 3);
    }

    public function test_program_can_manage_budget_master_while_head_is_read_only(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $head = User::factory()->role(User::ROLE_HEAD)->create();

        $this->actingAs($program)->get(route('program.budget.edit'))->assertOk();
        $this->post(route('program.tariffs.store'), [
            'kota_tujuan' => 'Kendari',
            'uang_saku_per_hari' => 430000,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('master_tarif', ['kota_tujuan' => 'Kendari']);

        $this->actingAs($head)->get(route('dashboard.head'))
            ->assertOk()
            ->assertSeeText('Monitoring Pimpinan');
        $this->get(route('program.budget.edit'))->assertForbidden();
    }

    public function test_system_health_page_is_restricted_to_admin(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $employee = User::factory()->create();

        $this->actingAs($admin)->get(route('admin.system-health'))
            ->assertOk()
            ->assertSeeText('Database')
            ->assertSeeText('Template dokumen')
            ->assertDontSee((string) config('sim_pd.documents.libreoffice.binary'));

        $this->actingAs($employee)->get(route('admin.system-health'))->assertForbidden();
    }

    private function travelOrderData(User $employee): array
    {
        return [
            'no_spt' => 'SNAPSHOT/001',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/SNAPSHOT/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employee->id],
            'kota_tujuan' => 'Jakarta',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => BudgetAccount::query()->value('code'),
        ];
    }

    private function pendingTravel(User $employee): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'REVISI/001',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/REVISI/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-11',
            'lama_hari' => 2,
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => BudgetAccount::query()->value('code'),
            'uang_harian_per_hari_snapshot' => 300000,
            'batas_hotel_per_hari_snapshot' => 500000,
            'batas_transport_snapshot' => 1500000,
            'biaya_hotel_real' => 700000,
            'biaya_tiket_real' => 1400000,
            'status' => PerjalananDinas::STATUS_PENDING,
        ]);
    }

    private function createReport(PerjalananDinas $travel): void
    {
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan tercapai.',
            'tanggal_laporan' => '2026-08-12',
        ]);
    }
}
