<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TravelReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_must_submit_a_complete_report_and_can_only_submit_it_once(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $reportUrl = route('travel-reports.update', ['travel' => $travel->id]);

        $this->actingAs($owner)
            ->put($reportUrl, [
                'hasil_pelaksanaan' => 'Hasil pertama.',
                'kesimpulan' => '',
            ])
            ->assertSessionHasErrors('kesimpulan');

        $this->assertDatabaseMissing('laporan_perjadin', [
            'perjalanan_dinas_id' => $travel->id,
        ]);

        $this->actingAs($owner)
            ->put($reportUrl, [
                'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
                'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
                'foto_dokumentasi' => [
                    UploadedFile::fake()->image(
                        'dokumentasi.jpg',
                        1200,
                        900
                    ),
                ],
            ])
            ->assertRedirect(route('realizations.show', ['id' => $travel->id]));

        $this->assertDatabaseHas('laporan_perjadin', [
            'perjalanan_dinas_id' => $travel->id,
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
        ]);

        $this->actingAs($owner)
            ->put($reportUrl, [
                'hasil_pelaksanaan' => 'Isi yang mencoba menimpa laporan.',
                'kesimpulan' => 'Kesimpulan yang mencoba menimpa laporan.',
            ])
            ->assertRedirect(route('realizations.show', ['id' => $travel->id]));

        $this->assertDatabaseCount('laporan_perjadin', 1);
        $this->assertDatabaseHas('laporan_perjadin', [
            'perjalanan_dinas_id' => $travel->id,
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
        ]);
    }

    public function test_existing_report_form_url_redirects_to_realization(): void
    {
        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $this->createReport($travel);

        $this->actingAs($owner)
            ->get(route('travel-reports.edit', ['travel' => $travel->id]))
            ->assertRedirect(route('realizations.show', ['id' => $travel->id]))
            ->assertSessionHas(
                'success',
                'Laporan perjalanan sudah tersimpan dan hanya dapat diisi satu kali. Silakan lanjutkan pengisian realisasi biaya.'
            );
    }

    public function test_realization_get_and_post_remain_blocked_without_a_report(): void
    {
        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $reportUrl = route('travel-reports.edit', ['travel' => $travel->id]);

        $this->actingAs($owner)
            ->get(route('realizations.show', ['id' => $travel->id]))
            ->assertRedirect($reportUrl)
            ->assertSessionHasErrors('laporan');

        $this->actingAs($owner)
            ->post(route('realizations.store'), [
                'id' => $travel->id,
                'biaya_hotel' => 500000,
                'biaya_tiket' => 1800000,
            ])
            ->assertRedirect($reportUrl)
            ->assertSessionHasErrors('laporan');

        $this->assertSame(PerjalananDinas::STATUS_READY, $travel->fresh()->status);
    }

    public function test_realization_back_link_and_dashboard_do_not_return_to_saved_report_form(): void
    {
        $owner = User::factory()->create();
        $travel = $this->createTravel($owner);
        $this->createReport($travel);

        $reportUrl = route('travel-reports.edit', ['travel' => $travel->id]);
        $realizationUrl = route('realizations.show', ['id' => $travel->id]);

        $this->actingAs($owner)
            ->get($realizationUrl)
            ->assertOk()
            ->assertSee('Kembali ke Halaman Utama')
            ->assertSee(route('dashboard.user'), false)
            ->assertDontSee($reportUrl, false);

        $this->actingAs($owner)
            ->get(route('dashboard.user'))
            ->assertOk()
            ->assertSeeText('Laporan Tersimpan / Realisasi Belum Dikirim')
            ->assertSeeText('Lanjutkan Realisasi')
            ->assertSee($realizationUrl, false)
            ->assertDontSee($reportUrl, false);
    }

    public function test_report_access_rules_remain_scoped_to_owner_and_ready_status(): void
    {
        $owner = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $travel = $this->createTravel($owner);
        $reportUrl = route('travel-reports.edit', ['travel' => $travel->id]);

        $this->actingAs($otherEmployee)
            ->get($reportUrl)
            ->assertNotFound();

        $travel->update(['status' => PerjalananDinas::STATUS_PENDING]);

        $this->actingAs($owner)
            ->get($reportUrl)
            ->assertForbidden();
    }

    private function createTravel(User $owner): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'REPORT/FLOW/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/REPORT/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'user_id' => $owner->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 4610000,
            'status' => PerjalananDinas::STATUS_READY,
        ]);
    }

    private function createReport(PerjalananDinas $travel): void
    {
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan telah tercapai.',
            'tanggal_laporan' => '2026-08-20',
        ]);
    }
}
