<?php

namespace Tests\Feature;

use App\Models\AirTransportImport;
use App\Models\AirTransportRegulation;
use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\LaporanPerjadin;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\RealisasiRincian;
use App\Models\Setting;
use App\Models\User;
use App\Services\AirTransportCsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AirTransportPmkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_imports_and_activates_both_complete_datasets_atomically(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $this->actingAs($program)->get(route('program.air-transport.terminal-template'))
            ->assertOk()->assertDownload('Referensi_Transportasi_Terminal_PMK_32_2025.csv');
        $this->get(route('program.air-transport.airfare-template'))
            ->assertOk()->assertDownload('Referensi_Tiket_Pesawat_PMK_32_2025.csv');

        $this->post(route('program.air-transport.imports.upload'), [
            'terminal_transport_csv' => UploadedFile::fake()->createWithContent('terminal.csv', $this->terminalCsv(true)),
            'airfare_csv' => UploadedFile::fake()->createWithContent('tiket.csv', $this->airfareCsv(true)),
        ])->assertRedirect();
        $import = AirTransportImport::query()->firstOrFail();
        $this->assertCount(34, $import->terminal_rows);
        $this->assertCount(316, $import->airfare_rows);

        $this->get(route('program.air-transport.imports.preview', $import))
            ->assertOk()->assertSeeText('34 provinsi terminal')->assertSeeText('316 pasangan tiket');
        $this->post(route('program.air-transport.imports.commit', $import))
            ->assertRedirect(route('program.budget.edit'));
        $regulation = AirTransportRegulation::query()->firstOrFail();
        $this->assertSame(34, $regulation->terminalRates()->count());
        $this->assertSame(316, $regulation->airfareRates()->count());
        $this->post(route('program.air-transport.activate', $regulation))->assertRedirect();
        $this->assertSame(AirTransportRegulation::STATUS_ACTIVE, $regulation->fresh()->status);
    }

    public function test_plane_spt_uses_component_snapshots_and_ticket_reverse_fallback(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account, $regulation] = $this->activeContext('Jakarta', '31', 'JAKARTA');

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload($employee, $account, 'AIR/JKT', 'Jakarta'))
            ->assertRedirect(route('dashboard.officer'));
        $travel = PerjalananDinas::query()->where('no_spt', 'AIR/JKT')->firstOrFail();

        $this->assertSame('pmk_air', $travel->transport_rate_source);
        $this->assertSame($regulation->id, $travel->air_transport_regulation_id);
        $this->assertSame('181000.00', $travel->terminal_origin_one_way_snapshot);
        $this->assertSame('250000.00', $travel->terminal_destination_one_way_snapshot);
        $this->assertSame('3829000.00', $travel->airfare_economy_pp_snapshot);
        $this->assertSame('4691000.00', $travel->batas_transport_snapshot);
        $this->assertSame('pmk', $travel->airfare_rate_source);
    }

    public function test_missing_makassar_bandung_ticket_uses_legacy_ticket_without_losing_terminal_rates(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext('Bandung', '32', 'BANDUNG');

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload($employee, $account, 'AIR/BDG', 'Bandung'));
        $travel = PerjalananDinas::query()->where('no_spt', 'AIR/BDG')->firstOrFail();

        $this->assertSame('pmk_air', $travel->transport_rate_source);
        $this->assertSame('pmk', $travel->terminal_origin_source);
        $this->assertSame('pmk', $travel->terminal_destination_source);
        $this->assertSame('legacy', $travel->airfare_rate_source);
        $this->assertSame('2000000.00', $travel->airfare_economy_pp_snapshot);
        $this->assertSame('2722000.00', $travel->batas_transport_snapshot);
    }

    public function test_airfare_overrun_requires_reason_and_verifier_approves_the_proven_actual_value(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext('Jakarta', '31', 'JAKARTA');
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload($employee, $account, 'AIR/CLAIM', 'Jakarta'));
        $travel = PerjalananDinas::query()->where('no_spt', 'AIR/CLAIM')->firstOrFail();
        LaporanPerjadin::query()->create([
            'perjalanan_dinas_id' => $travel->id,
            'tanggal_laporan' => '2026-08-10',
            'hasil_pelaksanaan' => 'Kegiatan selesai.',
            'kesimpulan' => 'Sesuai rencana.',
        ]);
        $payload = $this->realizationPayload(
            $travel,
            0,
            4_000_000,
            [],
            [UploadedFile::fake()->image('tiket.jpg', 800, 800)]
        );

        $this->actingAs($employee)->post(route('realizations.store'), $payload)
            ->assertSessionHasErrors('items.flight_ticket.overrun_reason');
        $payload['items']['flight_ticket']['overrun_reason'] = 'Tarif penerbangan tersedia pada jadwal penugasan.';
        $this->post(route('realizations.store'), $payload)->assertRedirect(route('dashboard.user'));

        $travel->refresh();
        $ticket = $travel->rincianRealisasi()->where('kode', RealisasiRincian::CODE_FLIGHT_TICKET)->firstOrFail();
        $this->assertSame('pmk', $ticket->benchmark_source);
        $this->assertSame('3829000.00', $ticket->benchmark_amount_snapshot);
        $this->assertSame('Tarif penerbangan tersedia pada jadwal penugasan.', $ticket->overrun_reason);

        $this->actingAs($verifier)->get(route('verifications.show', ['id' => $travel->id]))
            ->assertOk()->assertSeeText('Melebihi patokan PMK Rp 171.000')->assertSeeText('Tarif penerbangan tersedia');
        $this->post(route('verifications.store'), $this->approvalPayload($travel))
            ->assertRedirect(route('dashboard.verifier'));
        $this->assertSame('4000000.00', $travel->fresh()->biaya_tiket_approved);
    }

    public function test_terminal_overrun_requires_both_pmk_declarations(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext('Jakarta', '31', 'JAKARTA');
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload($employee, $account, 'AIR/TERMINAL', 'Jakarta'));
        $travel = PerjalananDinas::query()->where('no_spt', 'AIR/TERMINAL')->firstOrFail();
        LaporanPerjadin::query()->create([
            'perjalanan_dinas_id' => $travel->id, 'tanggal_laporan' => '2026-08-10',
            'hasil_pelaksanaan' => 'Selesai.', 'kesimpulan' => 'Sesuai.',
        ]);

        $payload = $this->realizationPayload($travel, 0, 0);
        $payload['items']['local_departure']['amount'] = 200000;
        $payload['items']['local_departure']['evidence'] = [UploadedFile::fake()->image('terminal.jpg', 800, 800)];
        $payload['items']['local_departure']['overrun_reason'] = 'Kendaraan dinas tidak tersedia.';
        $this->actingAs($employee)->post(route('realizations.store'), $payload)
            ->assertSessionHasErrors('items.local_departure.office_route_confirmed');

        $payload = $this->realizationPayload($travel, 0, 0);
        $payload['items']['local_departure'] = array_replace($payload['items']['local_departure'], [
            'amount' => 200000,
            'evidence' => [UploadedFile::fake()->image('terminal-valid.jpg', 800, 800)],
            'overrun_reason' => 'Kendaraan dinas tidak tersedia.',
            'office_route_confirmed' => 1,
            'non_private_vehicle_confirmed' => 1,
        ]);
        $this->post(route('realizations.store'), $payload)->assertRedirect(route('dashboard.user'));
        $detail = $travel->rincianRealisasi()->where('kode', RealisasiRincian::CODE_LOCAL_DEPARTURE)->firstOrFail();
        $this->assertTrue($detail->office_route_confirmed);
        $this->assertTrue($detail->non_private_vehicle_confirmed);
    }

    /** @return array{User, User, User, BudgetAccount, AirTransportRegulation} */
    private function activeContext(string $destination, string $provinceCode, string $airfareCity): array
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $province = Province::query()->where('code', $provinceCode)->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => $destination,
            'province_id' => $province->id,
            'airfare_city' => $airfareCity,
            'airfare_city_key' => app(AirTransportCsvService::class)->normalizeCity($airfareCity),
            'uang_saku_per_hari' => 430000,
        ]);
        Setting::query()->insert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ]);
        $account = BudgetAccount::query()->create(['code' => 'MAK-AIR', 'is_active' => true]);
        $this->activateSupportingDatasets($program);
        $this->actingAs($program)->post(route('program.air-transport.imports.upload'), [
            'terminal_transport_csv' => UploadedFile::fake()->createWithContent('terminal.csv', $this->terminalCsv()),
            'airfare_csv' => UploadedFile::fake()->createWithContent('tiket.csv', $this->airfareCsv()),
        ]);
        $import = AirTransportImport::query()->firstOrFail();
        $this->post(route('program.air-transport.imports.commit', $import));
        $regulation = AirTransportRegulation::query()->firstOrFail();
        $this->post(route('program.air-transport.activate', $regulation));

        return [$program, $officer, $employee, $account, $regulation];
    }

    private function activateSupportingDatasets(User $program): void
    {
        $daily = DailyAllowanceRegulation::query()->create([
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => DailyAllowanceRegulation::STATUS_ACTIVE,
            'original_filename' => 'daily.csv', 'csv_path' => 'daily.csv', 'csv_sha256' => str_repeat('1', 64),
            'uploaded_by' => $program->id, 'activated_by' => $program->id, 'activated_at' => now(),
        ]);
        $hotel = HotelRegulation::query()->create([
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => HotelRegulation::STATUS_ACTIVE,
            'original_filename' => 'hotel.csv', 'csv_path' => 'hotel.csv', 'csv_sha256' => str_repeat('2', 64),
            'uploaded_by' => $program->id, 'activated_by' => $program->id, 'activated_at' => now(),
        ]);
        foreach (Province::query()->get() as $province) {
            DailyAllowanceRate::query()->create([
                'daily_allowance_regulation_id' => $daily->id, 'province_id' => $province->id,
                'outside_city' => 430000, 'inside_city_over_8_hours' => 170000, 'training' => 130000,
            ]);
            HotelRate::query()->create([
                'hotel_regulation_id' => $hotel->id, 'province_id' => $province->id,
                'rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III, 'amount' => 735000,
            ]);
        }
    }

    private function sptPayload(User $employee, BudgetAccount $account, string $number, string $destination): array
    {
        return [
            'no_spt' => $number, 'menimbang' => 'Kebutuhan dinas', 'no_memo' => 'MEMO/'.$number,
            'perihal_memo' => 'Koordinasi', 'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi', 'user_ids' => [$employee->id],
            'kota_tujuan' => $destination, 'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10', 'tgl_kembali' => '2026-08-10',
            'angkutan' => 'Pesawat Udara', 'akun_anggaran' => $account->code,
            'daily_allowance_category' => DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
        ];
    }

    private function terminalCsv(bool $reverse = false): string
    {
        $rows = app(AirTransportCsvService::class)->terminalCatalogRows();
        if ($reverse) $rows = $rows->reverse()->values();
        $lines = [implode(',', AirTransportCsvService::TERMINAL_HEADERS)];
        foreach ($rows as $row) $lines[] = '"'.$row['nama_provinsi'].'",'.$row['tarif_sumber'];
        return implode("\n", $lines)."\n";
    }

    private function airfareCsv(bool $reverse = false): string
    {
        $rows = app(AirTransportCsvService::class)->airfareCatalogRows();
        if ($reverse) $rows = $rows->reverse()->values();
        $lines = [implode(',', AirTransportCsvService::AIRFARE_HEADERS)];
        foreach ($rows as $row) {
            $lines[] = implode(',', [$row['kota_asal'], $row['kota_tujuan'], $row['tarif_bisnis_sumber'], $row['tarif_ekonomi_sumber']]);
        }
        return implode("\n", $lines)."\n";
    }
}
