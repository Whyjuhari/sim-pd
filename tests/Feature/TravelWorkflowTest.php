<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_existing_business_flow_is_preserved(): void
    {
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employeeA = User::factory()->create();
        $employeeB = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();

        MasterTarif::query()->create(['kota_tujuan' => 'Jakarta', 'uang_saku_per_hari' => 370000]);
        Setting::query()->insert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ]);

        $this->actingAs($officer)->post(route('travel-orders.store'), [
            'no_spt' => 'TEST/001',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employeeA->id, $employeeB->id],
            'kota_tujuan' => 'Jakarta',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
        ])->assertRedirect(route('dashboard.officer'));

        $this->assertDatabaseCount('transaksi_perjadin', 2);
        $groupIds = PerjalananDinas::query()->pluck('spt_group_id')->unique();
        $this->assertCount(1, $groupIds);
        $this->assertNotNull($groupIds->first());

        $officerDashboard = $this->actingAs($officer)->get(route('dashboard.officer'));
        $officerDashboard->assertOk()
            ->assertSeeText('pegawai dalam satu Surat Tugas')
            ->assertSeeText($employeeA->nama_lengkap)
            ->assertSeeText($employeeB->nama_lengkap);
        $this->assertSame(1, substr_count($officerDashboard->getContent(), '/documents/surat-tugas?id='));

        $travel = PerjalananDinas::query()->where('user_id', $employeeA->id)->firstOrFail();
        $this->assertSame(3, $travel->lama_hari);
        $this->assertSame('4610000.00', $travel->estimasi_biaya);
        $this->assertSame(PerjalananDinas::STATUS_READY, $travel->status);

        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Koordinasi terlaksana.',
            'kesimpulan' => 'Tujuan perjalanan tercapai.',
            'tanggal_laporan' => '2026-08-12',
        ]);

        $this->actingAs($employeeA)->post(route('realizations.store'), [
            'id' => $travel->id,
            'biaya_hotel' => 700000,
            'biaya_tiket' => 1800000,
        ])->assertRedirect(route('dashboard.user'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_PENDING, $travel->status);

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'hotel_approved' => 500000,
            'tiket_approved' => 1800000,
            'catatan' => 'Sesuai pagu',
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_APPROVED, $travel->status);
        $this->assertSame('3410000.00', $travel->total_cair);
        $this->assertSame('500000.00', $travel->biaya_hotel_approved);
        $this->assertSame('1800000.00', $travel->biaya_tiket_real);
        $this->assertSame('1800000.00', $travel->biaya_tiket_approved);
        $this->assertSame($verifier->id, $travel->verified_by);
        $this->assertDatabaseCount('perjalanan_dinas_status_histories', 2);
    }

    public function test_employee_cannot_report_another_employees_travel(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $travel = PerjalananDinas::query()->create([
            'no_spt' => 'TEST/002', 'menimbang' => 'Test', 'no_memo' => 'M-2', 'perihal_memo' => 'Test',
            'user_id' => $owner->id, 'kota_tujuan' => 'Makassar', 'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-10', 'lama_hari' => 1, 'status' => PerjalananDinas::STATUS_READY,
        ]);

        $this->actingAs($other)->get('/form_realisasi.php?id='.$travel->id)->assertNotFound();
    }
}
