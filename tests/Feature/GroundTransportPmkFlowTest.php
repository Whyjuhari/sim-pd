<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\GroundTransportImport;
use App\Models\GroundTransportRate;
use App\Models\GroundTransportRegulation;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\RealisasiRincian;
use App\Models\Setting;
use App\Models\User;
use App\Services\GroundTransportCsvService;
use App\Services\RealizationDetailService;
use App\Services\TravelCostCalculator;
use App\Repositories\PerjalananDinasRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroundTransportPmkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_can_download_the_370_route_template(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $response = $this->actingAs($program)->get(route('program.ground-transport.template'));

        $response->assertOk()->assertDownload('Template_Transportasi_Darat_PMK_32_2025.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'nama_provinsi,ibukota_provinsi,kabupaten_kota_tujuan,tarif_one_way',
            $content
        );
        $this->assertStringContainsString('"SULAWESI SELATAN",Makassar,"Kab. Barru",', $content);
        $this->assertStringContainsString('PAPUA,Jayapura,"Kab. Jayapura",', $content);
        $this->assertStringContainsString('"PAPUA BARAT",Manokwari,"Kab. Teluk Bintuni",', $content);
        $this->assertCount(371, preg_split('/\r\n|\r|\n/', trim($content)));
    }

    public function test_csv_may_be_reordered_and_is_validated_against_all_official_routes(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $this->actingAs($program)->post(route('program.ground-transport.imports.upload'), [
            'ground_transport_csv' => UploadedFile::fake()->createWithContent(
                'transport.csv',
                $this->validCsv(true)
            ),
        ])->assertRedirect();

        $import = GroundTransportImport::query()->firstOrFail();
        $this->assertSame(GroundTransportImport::STATUS_VALIDATED, $import->status);
        $this->assertCount(370, $import->parsed_rows);
        $this->assertSame(
            210000,
            collect($import->parsed_rows)
                ->first(fn (array $row): bool => $row['ibukota_provinsi'] === 'Makassar'
                    && $row['kabupaten_kota_tujuan'] === 'Kab. Barru')['amount']
        );

        $missingRouteCsv = implode("\n", array_slice(explode("\n", trim($this->validCsv())), 0, -1))."\n";
        $this->actingAs($program)->post(route('program.ground-transport.imports.upload'), [
            'ground_transport_csv' => UploadedFile::fake()->createWithContent(
                'transport-kurang.csv',
                $missingRouteCsv
            ),
        ])->assertSessionHasErrors('ground_transport_csv');
    }

    public function test_import_and_activation_are_restricted_to_program_and_sync_master_destinations(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $this->actingAs($employee)->get(route('program.ground-transport.template'))->assertForbidden();

        [$program] = $this->baseContext();
        $this->actingAs($program)->post(route('program.ground-transport.imports.upload'), [
            'ground_transport_csv' => UploadedFile::fake()->createWithContent('transport.csv', $this->validCsv()),
        ]);
        $import = GroundTransportImport::query()->firstOrFail();
        $this->get(route('program.ground-transport.imports.preview', $import))
            ->assertOk()->assertSeeText('370 rute')->assertSeeText('Rp 210.000');
        $this->post(route('program.ground-transport.imports.commit', $import))
            ->assertRedirect(route('program.budget.edit'));
        $regulation = GroundTransportRegulation::query()->firstOrFail();
        $this->assertSame(370, $regulation->rates()->count());

        $this->post(route('program.ground-transport.activate', $regulation))->assertRedirect();
        $this->assertSame(GroundTransportRegulation::STATUS_ACTIVE, $regulation->fresh()->status);
        $this->assertDatabaseHas('master_tarif', [
            'kota_tujuan' => 'Kab. Barru',
            'ground_transport_source' => 'pmk',
            'uang_saku_per_hari' => 0,
        ]);
    }

    public function test_makassar_barru_and_reverse_use_the_same_one_way_snapshot(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account, $regulation] = $this->activeContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/001', 'Makassar', 'Kab. Barru'
        ))->assertRedirect(route('dashboard.officer'));

        $outbound = PerjalananDinas::query()->firstOrFail();
        $this->assertSame('pmk_ground', $outbound->transport_rate_source);
        $this->assertSame($regulation->id, $outbound->ground_transport_regulation_id);
        $this->assertSame('210000.00', $outbound->ground_transport_one_way_snapshot);
        $this->assertSame('420000.00', $outbound->batas_transport_snapshot);
        $this->assertSame([
            RealisasiRincian::CODE_GROUND_OUTBOUND,
            RealisasiRincian::CODE_GROUND_RETURN,
        ], collect(app(RealizationDetailService::class)->presets($outbound))->pluck('code')->all());

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/002', 'Kab. Barru', 'Makassar'
        ))->assertRedirect(route('dashboard.officer'));
        $reverse = PerjalananDinas::query()->where('no_spt', 'GROUND/002')->firstOrFail();
        $this->assertSame('pmk_ground', $reverse->transport_rate_source);
        $this->assertSame('210000.00', $reverse->ground_transport_one_way_snapshot);
        $this->assertSame('420000.00', $reverse->batas_transport_snapshot);
    }

    public function test_pangkep_makassar_stays_legacy_and_plane_is_not_changed(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/LEGACY', 'Pangkep', 'Makassar'
        ));
        $legacy = PerjalananDinas::query()->where('no_spt', 'GROUND/LEGACY')->firstOrFail();
        $this->assertSame('legacy', $legacy->transport_rate_source);
        $this->assertSame('1000000.00', $legacy->batas_transport_snapshot);
        $this->assertNull($legacy->ground_transport_regulation_id);

        $payload = $this->sptPayload($employee, $account, 'GROUND/PLANE', 'Makassar', 'Kab. Barru');
        $payload['angkutan'] = 'Pesawat Udara';
        $this->actingAs($officer)->post(route('travel-orders.store'), $payload);
        $plane = PerjalananDinas::query()->where('no_spt', 'GROUND/PLANE')->firstOrFail();
        $this->assertSame('legacy', $plane->transport_rate_source);
        $this->assertSame('2000000.00', $plane->batas_transport_snapshot);
    }

    public function test_jakarta_bogor_uses_the_special_surrounding_area_rate(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/JAKARTA', 'Jakarta', 'Kota Bogor'
        ))->assertRedirect(route('dashboard.officer'));

        $travel = PerjalananDinas::query()->where('no_spt', 'GROUND/JAKARTA')->firstOrFail();
        $this->assertSame('pmk_ground', $travel->transport_rate_source);
        $this->assertSame('270000.00', $travel->ground_transport_one_way_snapshot);
        $this->assertSame('540000.00', $travel->batas_transport_snapshot);
        $this->assertSame(
            '32',
            (string) MasterTarif::query()->where('kota_tujuan', 'Kota Bogor')->firstOrFail()->province->code
        );
    }

    public function test_new_revision_does_not_change_an_existing_ground_snapshot(): void
    {
        Storage::fake('local');
        [$program, $officer, $employee, $account, $firstRegulation] = $this->activeContext();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/SNAPSHOT/OLD', 'Makassar', 'Kab. Barru'
        ));
        $oldTravel = PerjalananDinas::query()->where('no_spt', 'GROUND/SNAPSHOT/OLD')->firstOrFail();

        $this->actingAs($program)->post(route('program.ground-transport.imports.upload'), [
            'ground_transport_csv' => UploadedFile::fake()->createWithContent(
                'transport-revisi.csv',
                $this->validCsv(false, 225000)
            ),
        ]);
        $import = GroundTransportImport::query()
            ->where('status', GroundTransportImport::STATUS_VALIDATED)
            ->firstOrFail();
        $this->post(route('program.ground-transport.imports.commit', $import));
        $secondRegulation = GroundTransportRegulation::query()->where('revision', 2)->firstOrFail();
        $this->post(route('program.ground-transport.activate', $secondRegulation));

        $oldTravel->refresh();
        $this->assertSame($firstRegulation->id, $oldTravel->ground_transport_regulation_id);
        $this->assertSame('210000.00', $oldTravel->ground_transport_one_way_snapshot);
        $this->assertSame(GroundTransportRegulation::STATUS_INACTIVE, $firstRegulation->fresh()->status);

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/SNAPSHOT/NEW', 'Makassar', 'Kab. Barru'
        ));
        $newTravel = PerjalananDinas::query()->where('no_spt', 'GROUND/SNAPSHOT/NEW')->firstOrFail();
        $this->assertSame($secondRegulation->id, $newTravel->ground_transport_regulation_id);
        $this->assertSame('225000.00', $newTravel->ground_transport_one_way_snapshot);
    }

    public function test_official_ground_claims_are_not_capped_and_custom_transport_is_zero(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeContext();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'GROUND/CLAIM', 'Makassar', 'Kab. Barru'
        ));
        $travel = PerjalananDinas::query()->where('no_spt', 'GROUND/CLAIM')->firstOrFail();
        $travel->update(['status' => PerjalananDinas::STATUS_PENDING]);
        $outbound = $travel->rincianRealisasi()->create([
            'kategori' => RealisasiRincian::CATEGORY_TRANSPORT,
            'kode' => RealisasiRincian::CODE_GROUND_OUTBOUND,
            'uraian' => 'Makassar ke Barru',
            'nilai_diajukan' => 300000,
            'is_preset' => true,
            'urutan' => 20,
        ]);
        $return = $travel->rincianRealisasi()->create([
            'kategori' => RealisasiRincian::CATEGORY_TRANSPORT,
            'kode' => RealisasiRincian::CODE_GROUND_RETURN,
            'uraian' => 'Barru ke Makassar',
            'nilai_diajukan' => 150000,
            'is_preset' => true,
            'urutan' => 30,
        ]);
        $custom = $travel->rincianRealisasi()->create([
            'kategori' => RealisasiRincian::CATEGORY_TRANSPORT,
            'kode' => null,
            'uraian' => 'Transportasi tambahan',
            'nilai_diajukan' => 100000,
            'is_preset' => false,
            'urutan' => 100,
        ]);

        $recommendation = app(TravelCostCalculator::class)
            ->verificationRecommendation($travel->fresh()->getAttributes());
        $approved = app(RealizationDetailService::class)->recommendedApprovals(
            $travel->rincianRealisasi()->get(),
            $recommendation
        );
        $this->assertSame(300000.0, $approved[$outbound->id]);
        $this->assertSame(150000.0, $approved[$return->id]);
        $this->assertSame(0.0, $approved[$custom->id]);

        $this->actingAs($verifier)->get(route('verifications.show', ['id' => $travel->id]))
            ->assertOk()
            ->assertSeeText('Melebihi patokan PMK Rp 90.000')
            ->assertSeeText('Dalam patokan PMK')
            ->assertSeeText('Tidak ada pasangan tarif PMK');
        $this->post(route('verifications.store'), $this->approvalPayload($travel))
            ->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_APPROVED, $travel->status);
        $this->assertSame('450000.00', $travel->biaya_tiket_approved);
        $documentData = app(PerjalananDinasRepository::class)->findForDocument($travel->id);
        $this->assertSame(450000.0, $documentData['transport_bandara_dokumen']);
        $this->assertSame(0.0, $documentData['biaya_tiket_dokumen']);
    }

    /** @return array{User, User, User, BudgetAccount, GroundTransportRegulation} */
    private function activeContext(): array
    {
        [$program, $officer, $employee, $account] = $this->baseContext();
        $this->activateSupportingDatasets($program);
        $this->actingAs($program)->post(route('program.ground-transport.imports.upload'), [
            'ground_transport_csv' => UploadedFile::fake()->createWithContent('transport.csv', $this->validCsv()),
        ]);
        $import = GroundTransportImport::query()->firstOrFail();
        $this->post(route('program.ground-transport.imports.commit', $import));
        $regulation = GroundTransportRegulation::query()->firstOrFail();
        $this->post(route('program.ground-transport.activate', $regulation));

        return [$program, $officer, $employee, $account, $regulation];
    }

    /** @return array{User, User, User, BudgetAccount} */
    private function baseContext(): array
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $southSulawesi = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Makassar',
            'province_id' => $southSulawesi->id,
            'uang_saku_per_hari' => 370000,
        ]);
        Setting::query()->insert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ]);
        $account = BudgetAccount::query()->create(['code' => 'MAK-GROUND', 'is_active' => true]);

        return [$program, $officer, $employee, $account];
    }

    private function activateSupportingDatasets(User $program): void
    {
        $daily = DailyAllowanceRegulation::query()->create([
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => DailyAllowanceRegulation::STATUS_ACTIVE,
            'original_filename' => 'daily.csv', 'csv_path' => 'daily.csv',
            'csv_sha256' => str_repeat('1', 64), 'uploaded_by' => $program->id,
            'activated_by' => $program->id, 'activated_at' => now(),
        ]);
        $hotel = HotelRegulation::query()->create([
            'regulation_number' => 'PMK 32/2025', 'fiscal_year' => 2026, 'revision' => 1,
            'source_reference' => 'test', 'status' => HotelRegulation::STATUS_ACTIVE,
            'original_filename' => 'hotel.csv', 'csv_path' => 'hotel.csv',
            'csv_sha256' => str_repeat('2', 64), 'uploaded_by' => $program->id,
            'activated_by' => $program->id, 'activated_at' => now(),
        ]);
        foreach (Province::query()->get() as $province) {
            DailyAllowanceRate::query()->create([
                'daily_allowance_regulation_id' => $daily->id, 'province_id' => $province->id,
                'outside_city' => 430000, 'inside_city_over_8_hours' => 170000, 'training' => 130000,
            ]);
            HotelRate::query()->create([
                'hotel_regulation_id' => $hotel->id, 'province_id' => $province->id,
                'rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III, 'amount' => 745000,
            ]);
        }
    }

    private function sptPayload(
        User $employee,
        BudgetAccount $account,
        string $number,
        string $origin,
        string $destination
    ): array {
        return [
            'no_spt' => $number,
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/'.$number,
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employee->id],
            'kota_tujuan' => $destination,
            'tempat_berangkat' => $origin,
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-10',
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => $account->code,
            'daily_allowance_category' => DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
        ];
    }

    private function validCsv(bool $reverse = false, ?int $barruRate = null): string
    {
        $service = app(GroundTransportCsvService::class);
        $provinceNames = Province::query()->pluck('name', 'code');
        $rows = $service->catalogRows();
        if ($reverse) {
            $rows = $rows->reverse()->values();
        }
        $lines = [implode(',', GroundTransportCsvService::HEADERS)];
        foreach ($rows as $row) {
            $province = $provinceNames[$row['kode_provinsi']];
            if ($reverse && $row['kode_provinsi'] === '19') {
                $province = 'BANGKA BELITUNG';
            }
            $destination = $row['kabupaten_kota_tujuan'];
            if ($reverse && $destination === 'Kab. Barru') {
                $destination = 'Kabupaten Barru';
            }
            $amount = $row['tarif_sumber'];
            if ($row['ibukota_provinsi'] === 'Makassar' && $row['kabupaten_kota_tujuan'] === 'Kab. Barru') {
                $amount = $barruRate ?? $amount;
            }
            $lines[] = implode(',', [
                $province,
                $row['ibukota_provinsi'],
                $destination,
                $amount,
            ]);
        }

        return implode("\n", $lines)."\n";
    }
}
