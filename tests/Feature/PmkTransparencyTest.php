<?php

namespace Tests\Feature;

use App\Models\AirTransportRegulation;
use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\DomesticAirfareRate;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\RealisasiRincian;
use App\Models\Setting;
use App\Models\TerminalTransportRate;
use App\Models\User;
use App\Repositories\PerjalananDinasRepository;
use App\Services\AirTransportCsvService;
use App\Services\Documents\LumpsumXlsxGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PmkTransparencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_sees_compact_pmk_readiness_panel(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Tujuan Belum Dipetakan',
            'uang_saku_per_hari' => 0,
        ]);

        $this->actingAs($program)->get(route('program.budget.edit', ['readiness_year' => 2026]))
            ->assertOk()
            ->assertSeeText('Kesiapan Data PMK')
            ->assertSeeText('0/4 dataset aktif')
            ->assertSeeText('Perlu perhatian')
            ->assertSeeText('Tanpa provinsi');
    }

    public function test_officer_cost_preview_uses_the_same_backend_snapshots_as_spt_storage(): void
    {
        [$officer, $employee, $account] = $this->airContext();
        $payload = $this->sptPayload($employee, $account);

        $response = $this->actingAs($officer)->postJson(route('travel-orders.cost-preview'), [
            'kota_tujuan' => $payload['kota_tujuan'],
            'tempat_berangkat' => $payload['tempat_berangkat'],
            'tgl_berangkat' => $payload['tgl_berangkat'],
            'tgl_kembali' => $payload['tgl_kembali'],
            'angkutan' => $payload['angkutan'],
            'daily_allowance_category' => $payload['daily_allowance_category'],
        ])->assertOk()
            ->assertJsonPath('days', 3)
            ->assertJsonPath('total', 7451000)
            ->assertJsonPath('has_fallback', false)
            ->assertJsonPath('components.0.source', 'PMK')
            ->assertJsonPath('components.2.label', 'Terminal asal')
            ->assertJsonPath('components.3.total', 3829000);

        $this->post(route('travel-orders.store'), $payload)->assertRedirect(route('dashboard.officer'));
        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame((float) $response->json('total'), (float) $travel->estimasi_biaya);

        $this->actingAs($employee)->postJson(route('travel-orders.cost-preview'), $payload)->assertForbidden();
    }

    public function test_verifier_and_dashboards_share_the_same_exception_summary(): void
    {
        [, $employee] = $this->airContext();
        $travel = $this->pendingAirTravel($employee);
        $this->airDetails($travel);
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();

        $this->actingAs($verifier)->get(route('verifications.show', ['id' => $travel->id]))
            ->assertOk()
            ->assertSeeText('Ringkasan Pengecualian')
            ->assertSeeText('Dalam patokan')
            ->assertSeeText('Di atas patokan')
            ->assertSeeText('Legacy')
            ->assertSeeText('Tanpa patokan')
            ->assertSeeText('Selisih di atas patokan Rp 171.000');

        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $this->actingAs($program)->get(route('dashboard.program'))
            ->assertOk()->assertSeeText('Kepatuhan PMK');
        $head = User::factory()->role(User::ROLE_HEAD)->create();
        $this->actingAs($head)->get(route('dashboard.head', ['year' => 2026]))
            ->assertOk()->assertSeeText('Kepatuhan Biaya PMK');
    }

    public function test_program_and_head_exports_include_aggregate_pmk_compliance_sheet(): void
    {
        [, $employee] = $this->airContext();
        $travel = $this->pendingAirTravel($employee);
        $this->airDetails($travel);
        $paths = [];

        try {
            foreach ([
                [User::ROLE_PROGRAM, route('program.recap.export', ['format' => 'xlsx'])],
                [User::ROLE_HEAD, route('head.recap.export', ['format' => 'xlsx', 'year' => 2026])],
            ] as [$role, $url]) {
                $response = $this->actingAs(User::factory()->role($role)->create())->get($url)->assertOk();
                $path = $response->baseResponse->getFile()->getPathname();
                $paths[] = $path;
                $workbook = IOFactory::load($path);
                $sheet = $workbook->getSheetByName('Kepatuhan PMK');
                $this->assertNotNull($sheet);
                $this->assertSame('Melebihi patokan PMK', $sheet->getCell('A4')->getValue());
                $this->assertSame(1, $sheet->getCell('B4')->getValue());
                $workbook->disconnectWorksheets();
            }
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) @unlink($path);
            }
        }
    }

    public function test_air_pmk_approved_components_are_mapped_to_the_correct_lumpsum_rows(): void
    {
        [, $employee] = $this->airContext();
        $travel = $this->pendingAirTravel($employee);
        $travel->forceFill([
            'status' => PerjalananDinas::STATUS_APPROVED,
            'lama_hari' => 3,
            'total_cair' => 6_981_000,
        ])->save();

        foreach ([
            [RealisasiRincian::CATEGORY_HOTEL, RealisasiRincian::CODE_HOTEL, 1_000_000],
            [RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_LOCAL_DEPARTURE, 181_000],
            [RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_FLIGHT_TICKET, 3_829_000],
            [RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_DESTINATION_OUTBOUND, 250_000],
            [RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_DESTINATION_RETURN, 250_000],
            [RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_LOCAL_RETURN, 181_000],
        ] as $index => [$category, $code, $amount]) {
            RealisasiRincian::query()->create([
                'perjalanan_dinas_id' => $travel->id,
                'kategori' => $category,
                'kode' => $code,
                'uraian' => 'Komponen PMK '.($index + 1),
                'nilai_diajukan' => $amount,
                'nilai_disetujui' => $amount,
                'benchmark_source' => 'pmk',
                'benchmark_amount_snapshot' => $amount,
                'is_preset' => true,
                'urutan' => ($index + 1) * 10,
            ]);
        }

        $data = app(PerjalananDinasRepository::class)->findForDocument($travel->id);
        $this->assertNotNull($data);
        $this->assertSame(3_829_000.0, $data['biaya_tiket_dokumen']);
        $this->assertSame(862_000.0, $data['transport_bandara_dokumen']);
        $this->assertSame(1_000_000.0, $data['biaya_hotel_dokumen']);

        $directory = storage_path('framework/testing/pmk-air-documents');
        $path = (new LumpsumXlsxGenerator(
            resource_path('documents/SPD_157_Template.xlsx'),
            $directory,
        ))->generate($data);

        try {
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getSheetByName('Lumpsum');
            $this->assertNotNull($sheet);
            $this->assertSame(862_000.0, (float) $sheet->getCell('J10')->getValue());
            $this->assertSame(1_000_000.0, (float) $sheet->getCell('J11')->getValue());
            $this->assertSame(3_829_000.0, (float) $sheet->getCell('J12')->getValue());
            $this->assertSame(6_981_000.0, (float) $sheet->getCell('J14')->getValue());
            $workbook->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return array{User, User, BudgetAccount} */
    private function airContext(): array
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $sulsel = Province::query()->where('code', '73')->firstOrFail();
        $jakarta = Province::query()->where('code', '31')->firstOrFail();
        $normalizer = app(AirTransportCsvService::class);
        MasterTarif::query()->updateOrCreate(['kota_tujuan' => 'Jakarta'], [
            'province_id' => $jakarta->id,
            'airfare_city' => 'JAKARTA',
            'airfare_city_key' => $normalizer->normalizeCity('JAKARTA'),
            'uang_saku_per_hari' => 430000,
        ]);
        Setting::query()->upsert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ], ['nama_setting'], ['nilai_setting']);
        $account = BudgetAccount::query()->firstOrCreate(['code' => 'MAK-PMK'], ['is_active' => true]);

        $daily = DailyAllowanceRegulation::query()->create($this->regulationData($program, 'daily.csv', DailyAllowanceRegulation::STATUS_ACTIVE));
        DailyAllowanceRate::query()->create([
            'daily_allowance_regulation_id' => $daily->id,
            'province_id' => $jakarta->id,
            'outside_city' => 430000,
            'inside_city_over_8_hours' => 170000,
            'training' => 130000,
        ]);
        $hotel = HotelRegulation::query()->create($this->regulationData($program, 'hotel.csv', HotelRegulation::STATUS_ACTIVE));
        HotelRate::query()->create([
            'hotel_regulation_id' => $hotel->id,
            'province_id' => $jakarta->id,
            'rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III,
            'amount' => 735000,
        ]);
        $air = AirTransportRegulation::query()->create([
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => AirTransportRegulation::STATUS_ACTIVE,
            'terminal_original_filename' => 'terminal.csv', 'terminal_csv_path' => 'terminal.csv',
            'terminal_csv_sha256' => str_repeat('3', 64), 'airfare_original_filename' => 'airfare.csv',
            'airfare_csv_path' => 'airfare.csv', 'airfare_csv_sha256' => str_repeat('4', 64),
            'uploaded_by' => $program->id, 'activated_by' => $program->id, 'activated_at' => now(),
        ]);
        TerminalTransportRate::query()->insert([
            ['air_transport_regulation_id' => $air->id, 'province_id' => $sulsel->id, 'amount' => 181000, 'created_at' => now(), 'updated_at' => now()],
            ['air_transport_regulation_id' => $air->id, 'province_id' => $jakarta->id, 'amount' => 250000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DomesticAirfareRate::query()->create([
            'air_transport_regulation_id' => $air->id, 'source_number' => 1,
            'origin_city' => 'JAKARTA', 'destination_city' => 'MAKASSAR',
            'origin_key' => $normalizer->normalizeCity('JAKARTA'),
            'destination_key' => $normalizer->normalizeCity('MAKASSAR'),
            'business_amount' => 6500000, 'economy_amount' => 3829000,
        ]);

        return [$officer, $employee, $account];
    }

    private function regulationData(User $program, string $file, string $status): array
    {
        return [
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => $status, 'original_filename' => $file,
            'csv_path' => $file, 'csv_sha256' => str_repeat('1', 64),
            'uploaded_by' => $program->id, 'activated_by' => $program->id, 'activated_at' => now(),
        ];
    }

    private function sptPayload(User $employee, BudgetAccount $account): array
    {
        return [
            'no_spt' => 'PREVIEW/001', 'menimbang' => 'Kebutuhan dinas', 'no_memo' => 'MEMO/PREVIEW',
            'perihal_memo' => 'Koordinasi', 'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi', 'user_ids' => [$employee->id],
            'kota_tujuan' => 'Jakarta', 'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10', 'tgl_kembali' => '2026-08-12',
            'angkutan' => 'Pesawat Udara', 'akun_anggaran' => $account->code,
            'daily_allowance_category' => DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
        ];
    }

    private function pendingAirTravel(User $employee): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'no_spt' => 'COMPLIANCE/001', 'menimbang' => 'Kebutuhan dinas', 'no_memo' => 'MEMO/COMPLIANCE',
            'perihal_memo' => 'Koordinasi', 'user_id' => $employee->id,
            'maksud_perjalanan' => 'Koordinasi', 'kota_tujuan' => 'Jakarta', 'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10', 'tgl_kembali' => '2026-08-10', 'lama_hari' => 1,
            'angkutan' => 'Pesawat Udara', 'akun_anggaran' => 'MAK-PMK', 'status' => PerjalananDinas::STATUS_PENDING,
            'estimasi_biaya' => 5000000, 'uang_harian_per_hari_snapshot' => 430000,
            'batas_hotel_per_hari_snapshot' => 735000, 'hotel_nights_snapshot' => 0, 'hotel_rate_source' => 'pmk',
            'batas_transport_snapshot' => 4691000, 'transport_rate_source' => 'pmk_air',
            'terminal_origin_one_way_snapshot' => 181000, 'terminal_origin_source' => 'pmk',
            'terminal_destination_one_way_snapshot' => 250000, 'terminal_destination_source' => 'pmk',
            'airfare_economy_pp_snapshot' => 3829000, 'airfare_rate_source' => 'pmk',
        ]);
    }

    private function airDetails(PerjalananDinas $travel): void
    {
        foreach ([
            [RealisasiRincian::CODE_LOCAL_DEPARTURE, 150000, 'pmk', 181000],
            [RealisasiRincian::CODE_FLIGHT_TICKET, 4000000, 'pmk', 3829000],
            [RealisasiRincian::CODE_DESTINATION_OUTBOUND, 200000, 'legacy', 180000],
            ['transport_lainnya', 50000, 'no_benchmark', null],
        ] as $index => [$code, $amount, $source, $benchmark]) {
            RealisasiRincian::query()->create([
                'perjalanan_dinas_id' => $travel->id, 'kategori' => RealisasiRincian::CATEGORY_TRANSPORT,
                'kode' => $code, 'uraian' => 'Rincian '.$index, 'nilai_diajukan' => $amount,
                'benchmark_source' => $source, 'benchmark_amount_snapshot' => $benchmark,
                'is_preset' => $index < 3, 'urutan' => ($index + 1) * 10,
            ]);
        }
    }
}
