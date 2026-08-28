<?php

namespace Tests\Feature;

use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use App\Models\User;
use App\Repositories\PerjalananDinasRepository;
use App\Services\RealizationDetailService;
use App\Services\TravelCostCalculator;
use App\Support\EmployeeTravelStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealizationDetailFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_presets_follow_spt_transport_and_one_day_has_no_hotel(): void
    {
        $employee = User::factory()->create();
        $air = $this->createTravel($employee, ['angkutan' => 'Pesawat Udara', 'lama_hari' => 3]);
        $ground = $this->createTravel($employee, [
            'no_spt' => 'DETAIL/GROUND',
            'angkutan' => 'Transportasi Darat',
            'lama_hari' => 1,
            'tgl_kembali' => '2026-08-10',
        ]);

        $service = app(RealizationDetailService::class);
        $airCodes = collect($service->presets($air))->pluck('code');
        $groundCodes = collect($service->presets($ground))->pluck('code');

        $this->assertContains(RealisasiRincian::CODE_HOTEL, $airCodes);
        $this->assertContains(RealisasiRincian::CODE_FLIGHT_TICKET, $airCodes);
        $this->assertContains(RealisasiRincian::CODE_LOCAL_DEPARTURE, $airCodes);
        $this->assertSame([RealisasiRincian::CODE_GROUND_TRANSPORT], $groundCodes->all());

        $calculator = app(TravelCostCalculator::class);
        $this->assertSame(1300000.0, $calculator->estimate('Makassar', 1, 'Transportasi Darat'));
    }

    public function test_realization_preview_exposes_authorized_existing_evidence_metadata(): void
    {
        $employee = User::factory()->create();
        $travel = $this->createTravel($employee);
        $this->report($travel);
        $detail = $travel->rincianRealisasi()->create([
            'kategori' => RealisasiRincian::CATEGORY_HOTEL,
            'kode' => RealisasiRincian::CODE_HOTEL,
            'uraian' => 'Penginapan selama perjalanan dinas',
            'nilai_diajukan' => 500000,
            'nilai_disetujui' => null,
            'is_preset' => true,
            'urutan' => 10,
        ]);
        $evidence = $detail->bukti()->create([
            'perjalanan_dinas_id' => $travel->id,
            'jenis' => RealisasiRincian::CATEGORY_HOTEL,
            'path' => 'realization-evidence/hotel-rapat.jpg',
            'nama_asli' => 'hotel & rapat.jpg',
            'mime_type' => 'image/jpeg',
            'ukuran' => 2048,
            'urutan' => 1,
        ]);

        $response = $this->actingAs($employee)
            ->get(route('realizations.show', ['id' => $travel->id]));

        $response->assertOk();
        $response->assertSee('data-existing-evidence', false);
        $response->assertSee('data-evidence-name="hotel &amp; rapat.jpg"', false);
        $response->assertSee('data-evidence-mime="image/jpeg"', false);
        $response->assertSee('data-evidence-url="'.route('realization-evidence.show', $evidence).'"', false);
    }

    public function test_employee_stage_is_derived_from_dates_without_blocking_business_status(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $employee = User::factory()->create();

        $scheduled = $this->createTravel($employee, [
            'no_spt' => 'STAGE/SCHEDULED',
            'tgl_berangkat' => '2026-08-12',
            'tgl_kembali' => '2026-08-14',
        ]);
        $ongoing = $this->createTravel($employee, [
            'no_spt' => 'STAGE/ONGOING',
            'tgl_berangkat' => '2026-08-09',
            'tgl_kembali' => '2026-08-11',
        ]);
        $finished = $this->createTravel($employee, [
            'no_spt' => 'STAGE/FINISHED',
            'tgl_berangkat' => '2026-08-01',
            'tgl_kembali' => '2026-08-02',
        ]);

        $this->assertSame('Terjadwal', EmployeeTravelStage::for($scheduled)['label']);
        $this->assertSame('Sedang Berlangsung', EmployeeTravelStage::for($ongoing)['label']);
        $this->assertSame('Siap Dilaporkan', EmployeeTravelStage::for($finished)['label']);

        $finished->update(['status' => PerjalananDinas::STATUS_APPROVED]);
        $this->assertSame('Selesai', EmployeeTravelStage::for($finished->fresh())['label']);

        Carbon::setTestNow();
    }

    public function test_detailed_claim_is_approved_per_item_and_document_totals_remain_compatible(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee);
        $this->report($travel);

        $payload = $this->realizationPayload(
            $travel,
            600000,
            1000000,
            [UploadedFile::fake()->image('hotel.jpg', 900, 900)],
            [UploadedFile::fake()->image('tiket.jpg', 900, 900)]
        );
        $payload['items']['flight_ticket']['amount'] = 900000;
        $payload['items']['local_departure']['amount'] = 100000;
        $payload['items']['local_departure']['evidence'] = [
            UploadedFile::fake()->image('transport-lokal.jpg', 900, 900),
        ];
        $payload['items']['custom_taxi'] = [
            'id' => null,
            'code' => null,
            'description' => 'Taksi menuju lokasi rapat',
            'amount' => 100000,
            'evidence' => [UploadedFile::fake()->image('taksi.jpg', 900, 900)],
        ];

        $this->actingAs($employee)->post(route('realizations.store'), $payload)
            ->assertRedirect(route('dashboard.user'));

        $travel->refresh()->load('rincianRealisasi');
        $this->assertSame('600000.00', $travel->biaya_hotel_real);
        $this->assertSame('1100000.00', $travel->biaya_tiket_real);
        $this->assertCount(7, $travel->rincianRealisasi);

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'catatan' => 'Rincian sesuai bukti.',
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame('600000.00', $travel->biaya_hotel_approved);
        $this->assertSame('1100000.00', $travel->biaya_tiket_approved);
        $this->assertSame('2810000.00', $travel->total_cair);

        $document = app(PerjalananDinasRepository::class)->findForDocument($travel->id);
        $this->assertSame(900000.0, $document['biaya_tiket_dokumen']);
        $this->assertSame(200000.0, $document['transport_bandara_dokumen']);
        $this->assertSame(600000.0, $document['biaya_hotel_dokumen']);
    }

    public function test_verifier_cannot_override_system_amounts_and_transport_limit_is_shared_proportionally(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee);
        $this->report($travel);

        $payload = $this->realizationPayload(
            $travel,
            0,
            1800000,
            [],
            [UploadedFile::fake()->image('tiket.jpg', 800, 800)]
        );
        $payload['items']['local_departure']['amount'] = 600000;
        $payload['items']['local_departure']['evidence'] = [
            UploadedFile::fake()->image('transport-lokal.jpg', 800, 800),
        ];

        $this->actingAs($employee)->post(route('realizations.store'), $payload)
            ->assertRedirect(route('dashboard.user'));

        $travel->refresh()->load('rincianRealisasi');
        $verificationPage = $this->actingAs($verifier)->get(route('verifications.show', ['id' => $travel->id]));
        $verificationPage->assertOk()
            ->assertSeeText('Nilai Disetujui Sistem')
            ->assertDontSee('name="approved[', false);

        $maliciousValues = $travel->rincianRealisasi->mapWithKeys(fn (RealisasiRincian $detail): array => [
            $detail->id => 999999999,
        ])->all();

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'approved' => $maliciousValues,
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh()->load('rincianRealisasi');
        $ticket = $travel->rincianRealisasi->firstWhere('kode', RealisasiRincian::CODE_FLIGHT_TICKET);
        $local = $travel->rincianRealisasi->firstWhere('kode', RealisasiRincian::CODE_LOCAL_DEPARTURE);

        $this->assertSame(PerjalananDinas::STATUS_APPROVED, $travel->status);
        $this->assertSame('1500000.00', $ticket->nilai_disetujui);
        $this->assertSame('500000.00', $local->nilai_disetujui);
        $this->assertSame('2000000.00', $travel->biaya_tiket_approved);
        $this->assertSame('3110000.00', $travel->total_cair);
    }

    public function test_custom_transport_and_its_private_evidence_can_be_removed_on_revision(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee, ['angkutan' => 'Transportasi Darat']);
        $this->report($travel);

        $payload = $this->realizationPayload(
            $travel,
            0,
            200000,
            [],
            [UploadedFile::fake()->image('darat.jpg', 800, 800)]
        );
        $payload['items']['custom_terminal'] = [
            'id' => null,
            'code' => null,
            'description' => 'Transportasi terminal',
            'amount' => 50000,
            'evidence' => [UploadedFile::fake()->image('terminal.jpg', 800, 800)],
        ];

        $this->actingAs($employee)->post(route('realizations.store'), $payload)
            ->assertRedirect(route('dashboard.user'));
        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'reject',
            'catatan' => 'Transportasi tambahan tidak dapat diterima.',
        ])->assertRedirect(route('dashboard.verifier'));

        $custom = $travel->rincianRealisasi()->where('is_preset', false)->with('bukti')->firstOrFail();
        $customPath = $custom->bukti->firstOrFail()->path;
        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel->fresh(),
            0,
            200000,
            [],
            [],
            ['hapus_rincian' => [$custom->id]]
        ))->assertRedirect(route('dashboard.user'));

        $this->assertDatabaseMissing('realisasi_rincian', ['id' => $custom->id]);
        Storage::disk('local')->assertMissing($customPath);
        $this->assertSame('200000.00', $travel->fresh()->biaya_tiket_real);
    }

    public function test_detail_migration_backfills_legacy_totals_and_links_existing_evidence(): void
    {
        $originalConnection = \Illuminate\Support\Facades\DB::getDefaultConnection();
        config(['database.connections.legacy_detail_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        \Illuminate\Support\Facades\DB::setDefaultConnection('legacy_detail_test');

        try {
            \Illuminate\Support\Facades\Schema::create('transaksi_perjadin', function ($table): void {
                $table->integer('id')->autoIncrement();
                $table->string('status');
                $table->decimal('biaya_hotel_real', 15, 2)->default(0);
                $table->decimal('biaya_tiket_real', 15, 2)->default(0);
                $table->decimal('biaya_hotel_approved', 15, 2)->default(0);
                $table->decimal('biaya_tiket_approved', 15, 2)->default(0);
            });
            \Illuminate\Support\Facades\Schema::create('bukti_realisasi', function ($table): void {
                $table->id();
                $table->integer('perjalanan_dinas_id');
                $table->string('jenis');
                $table->string('path');
                $table->string('nama_asli');
                $table->string('mime_type');
                $table->unsignedInteger('ukuran');
                $table->unsignedSmallInteger('urutan');
                $table->timestamps();
            });
            \Illuminate\Support\Facades\DB::table('transaksi_perjadin')->insert([
                'id' => 1,
                'status' => PerjalananDinas::STATUS_APPROVED,
                'biaya_hotel_real' => 400000,
                'biaya_tiket_real' => 900000,
                'biaya_hotel_approved' => 350000,
                'biaya_tiket_approved' => 800000,
            ]);
            \Illuminate\Support\Facades\DB::table('bukti_realisasi')->insert([
                'perjalanan_dinas_id' => 1,
                'jenis' => 'hotel',
                'path' => 'realization-evidence/legacy.jpg',
                'nama_asli' => 'legacy.jpg',
                'mime_type' => 'image/jpeg',
                'ukuran' => 1000,
                'urutan' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration = require database_path('migrations/2026_08_22_100000_create_realisasi_rincian_table.php');
            $migration->up();

            $this->assertSame(2, \Illuminate\Support\Facades\DB::table('realisasi_rincian')->count());
            $this->assertDatabaseHas('realisasi_rincian', [
                'perjalanan_dinas_id' => 1,
                'kode' => 'legacy_hotel',
                'nilai_diajukan' => 400000,
                'nilai_disetujui' => 350000,
            ], 'legacy_detail_test');
            $hotelDetailId = \Illuminate\Support\Facades\DB::table('realisasi_rincian')
                ->where('kode', 'legacy_hotel')
                ->value('id');
            $this->assertDatabaseHas('bukti_realisasi', [
                'perjalanan_dinas_id' => 1,
                'realisasi_rincian_id' => $hotelDetailId,
            ], 'legacy_detail_test');
        } finally {
            \Illuminate\Support\Facades\DB::setDefaultConnection($originalConnection);
            \Illuminate\Support\Facades\DB::purge('legacy_detail_test');
        }
    }

    private function createTravel(User $employee, array $attributes = []): PerjalananDinas
    {
        \App\Models\MasterTarif::query()->firstOrCreate(
            ['kota_tujuan' => 'Makassar'],
            ['uang_saku_per_hari' => 300000]
        );
        foreach ([
            'batas_hotel' => 500000,
            'pagu_tiket' => 2000000,
            'batas_transport_darat' => 1000000,
        ] as $name => $value) {
            \App\Models\Setting::query()->firstOrCreate(
                ['nama_setting' => $name],
                ['nilai_setting' => $value]
            );
        }

        return PerjalananDinas::query()->create([
            'no_spt' => 'DETAIL/AIR',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/DETAIL',
            'perihal_memo' => 'Koordinasi',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-12',
            'lama_hari' => 3,
            'angkutan' => 'Pesawat Udara',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'uang_harian_per_hari_snapshot' => 370000,
            'batas_hotel_per_hari_snapshot' => 500000,
            'batas_transport_snapshot' => 2000000,
            'status' => PerjalananDinas::STATUS_READY,
            ...$attributes,
        ]);
    }

    private function report(PerjalananDinas $travel): void
    {
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan dan hasil tercapai.',
            'kesimpulan' => 'Simpulan dan saran tersedia.',
            'tanggal_laporan' => '2026-08-12',
        ]);
    }
}
