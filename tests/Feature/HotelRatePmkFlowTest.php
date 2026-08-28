<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\HotelImport;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\HotelRateCsvService;
use App\Services\TravelCostCalculator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HotelRatePmkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_can_download_name_based_hotel_template(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $response = $this->actingAs($program)->get(route('program.hotel-rates.template'));

        $response->assertOk()->assertDownload('Template_Biaya_Penginapan_PMK_32_2025.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'nama_provinsi,tarif_eselon_iv_golongan_iii_ii_i',
            $content
        );
        $this->assertStringContainsString('SULAWESI SELATAN', $content);
        $this->assertCount(39, preg_split('/\r\n|\r|\n/', trim($content)));
    }

    public function test_hotel_csv_accepts_pdf_aliases_and_arbitrary_row_order(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $this->actingAs($program)->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel.csv', $this->validCsv(true)),
        ])->assertRedirect();

        $import = HotelImport::query()->firstOrFail();
        $this->assertSame(HotelImport::STATUS_VALIDATED, $import->status);
        $this->assertCount(38, $import->parsed_rows);
        $this->assertSame(745000, collect($import->parsed_rows)->firstWhere('kode_provinsi', '73')['amount']);
    }

    public function test_incomplete_or_invalid_hotel_csv_is_rejected_before_storage(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $invalid = implode("\n", [
            implode(',', HotelRateCsvService::HEADERS),
            'SULAWESI SELATAN,tidak-valid',
        ])."\n";

        $this->actingAs($program)->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel.csv', $invalid),
        ])->assertSessionHasErrors('hotel_csv');

        $this->assertDatabaseCount('hotel_imports', 0);
        Storage::disk('local')->assertDirectoryEmpty('pmk/hotel/imports/temporary');
    }

    public function test_hotel_import_is_restricted_to_program_role(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get(route('program.hotel-rates.template'))->assertForbidden();
        $this->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel.csv', $this->validCsv()),
        ])->assertForbidden();
        $this->assertDatabaseCount('hotel_imports', 0);
    }

    public function test_program_previews_commits_and_activates_private_hotel_dataset(): void
    {
        Storage::fake('local');
        [$program] = $this->baseContext();

        $this->actingAs($program)->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel.csv', $this->validCsv()),
        ])->assertRedirect();
        $import = HotelImport::query()->firstOrFail();

        $this->get(route('program.hotel-rates.imports.preview', $import))
            ->assertOk()->assertSeeText('38 provinsi valid')->assertSeeText('Rp 745.000');
        $this->post(route('program.hotel-rates.imports.commit', $import))
            ->assertRedirect(route('program.budget.edit'))->assertSessionHasNoErrors();

        $regulation = HotelRegulation::query()->firstOrFail();
        $this->assertSame(HotelRegulation::STATUS_DRAFT, $regulation->status);
        $this->assertSame(38, $regulation->rates()->count());
        $jakarta = Province::query()->where('code', '31')->firstOrFail();
        $this->assertDatabaseHas('hotel_rates', [
            'hotel_regulation_id' => $regulation->id,
            'province_id' => $jakarta->id,
            'rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III,
            'amount' => 730000,
        ]);
        Storage::disk('local')->assertExists($regulation->csv_path);

        $this->post(route('program.hotel-rates.activate', $regulation))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(HotelRegulation::STATUS_ACTIVE, $regulation->fresh()->status);
    }

    public function test_new_spt_uses_two_hotel_nights_and_keeps_a_complete_snapshot(): void
    {
        Storage::fake('local');
        [$program, $officer, $employee, $account, $regulation, $province] = $this->activeDatasetContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee,
            $account,
            'HOTEL/PMK/001',
            '2026-08-10',
            '2026-08-12'
        ))->assertRedirect(route('dashboard.officer'));

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame('pmk', $travel->hotel_rate_source);
        $this->assertSame($regulation->id, $travel->hotel_regulation_id);
        $this->assertSame($province->id, $travel->hotel_province_id);
        $this->assertSame(HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III, $travel->hotel_rate_group);
        $this->assertSame('745000.00', $travel->batas_hotel_per_hari_snapshot);
        $this->assertSame(2, $travel->hotel_nights_snapshot);
        $this->assertSame('3600000.00', $travel->estimasi_biaya);
        $this->assertSame(User::ROLE_PROGRAM, $program->role);
    }

    public function test_one_day_pmk_trip_has_no_hotel_cost(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeDatasetContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee,
            $account,
            'HOTEL/PMK/ONE-DAY',
            '2026-08-10',
            '2026-08-10'
        ))->assertRedirect(route('dashboard.officer'));

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame(0, $travel->hotel_nights_snapshot);
        $this->assertSame('1370000.00', $travel->estimasi_biaya);
        $this->assertNotContains(
            \App\Models\RealisasiRincian::CODE_HOTEL,
            collect(app(\App\Services\RealizationDetailService::class)->presets($travel))->pluck('code')->all()
        );
    }

    public function test_hotel_claim_is_automatically_capped_by_pmk_without_verifier_input(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeDatasetContext();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();

        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee,
            $account,
            'HOTEL/PMK/CAP',
            '2026-08-10',
            '2026-08-12'
        ));
        $travel = PerjalananDinas::query()->firstOrFail();
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana.',
            'kesimpulan' => 'Kegiatan selesai.',
            'tanggal_laporan' => '2026-08-12',
        ]);

        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            1800000,
            0,
            [UploadedFile::fake()->image('hotel.jpg', 800, 800)],
            []
        ))->assertRedirect(route('dashboard.user'));

        $this->actingAs($verifier)->get(route('verifications.show', ['id' => $travel->id]))
            ->assertOk()->assertSeeText('PMK 32/2025')->assertSeeText('Rp 1.490.000');
        $this->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'approve',
            'approved' => [999999999],
        ])->assertRedirect(route('dashboard.verifier'));

        $travel->refresh();
        $this->assertSame('1490000.00', $travel->biaya_hotel_approved);
        $this->assertSame('2600000.00', $travel->total_cair);
    }

    public function test_multiday_hotel_claim_may_be_zero_without_evidence(): void
    {
        Storage::fake('local');
        [, $officer, $employee, $account] = $this->activeDatasetContext();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'HOTEL/PMK/OPTIONAL', '2026-08-10', '2026-08-12'
        ));
        $travel = PerjalananDinas::query()->firstOrFail();
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana.',
            'kesimpulan' => 'Kegiatan selesai.',
            'tanggal_laporan' => '2026-08-12',
        ]);

        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel, 0, 0, [], []
        ))->assertRedirect(route('dashboard.user'));

        $this->assertSame(PerjalananDinas::STATUS_PENDING, $travel->fresh()->status);
        $this->assertSame('0.00', $travel->fresh()->biaya_hotel_real);
    }

    public function test_new_hotel_revision_does_not_change_existing_spt_snapshot(): void
    {
        Storage::fake('local');
        [$program, $officer, $employee, $account, $firstRegulation] = $this->activeDatasetContext();
        $this->actingAs($officer)->post(route('travel-orders.store'), $this->sptPayload(
            $employee, $account, 'HOTEL/PMK/SNAPSHOT', '2026-08-10', '2026-08-12'
        ));
        $travel = PerjalananDinas::query()->firstOrFail();

        $this->actingAs($program)->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel-revisi.csv', $this->validCsv(false, 800000)),
        ]);
        $import = HotelImport::query()->where('status', HotelImport::STATUS_VALIDATED)->firstOrFail();
        $this->post(route('program.hotel-rates.imports.commit', $import));
        $secondRegulation = HotelRegulation::query()->where('revision', 2)->firstOrFail();
        $this->post(route('program.hotel-rates.activate', $secondRegulation));

        $travel->refresh();
        $this->assertSame($firstRegulation->id, $travel->hotel_regulation_id);
        $this->assertSame('745000.00', $travel->batas_hotel_per_hari_snapshot);
        $this->assertSame(HotelRegulation::STATUS_INACTIVE, $firstRegulation->fresh()->status);
        $this->assertSame(HotelRegulation::STATUS_ACTIVE, $secondRegulation->fresh()->status);
    }

    public function test_legacy_hotel_snapshot_is_not_reinterpreted_after_activation(): void
    {
        Storage::fake('local');
        [, , $employee] = $this->activeDatasetContext();
        $legacy = new PerjalananDinas([
            'user_id' => $employee->id,
            'kota_tujuan' => 'Makassar',
            'tgl_berangkat' => '2026-08-10',
            'hotel_rate_source' => null,
            'batas_hotel_per_hari_snapshot' => 500000,
            'lama_hari' => 3,
        ]);

        $rates = app(TravelCostCalculator::class)->ratesForOrder(
            'Makassar', 'Transportasi Darat', '2026-08-10', null, $legacy, 3
        );

        $this->assertSame('legacy', $rates['hotel_rate_source']);
        $this->assertSame(500000.0, $rates['hotel_per_day']);
        $this->assertSame(3, $rates['hotel_nights']);
        $this->assertNull($rates['hotel_regulation_id']);
    }

    public function test_year_after_hotel_cutover_requires_an_active_dataset(): void
    {
        Storage::fake('local');
        $this->activeDatasetContext();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tarif hotel PMK untuk tahun 2027 belum diaktifkan.');
        app(TravelCostCalculator::class)->ratesForOrder(
            'Makassar', 'Transportasi Darat', '2027-01-10', null, null, 2
        );
    }

    /** @return array{User, User, User, BudgetAccount, HotelRegulation, Province} */
    private function activeDatasetContext(): array
    {
        [$program, $officer, $employee, $account, $province] = $this->baseContext();
        $this->actingAs($program)->post(route('program.hotel-rates.imports.upload'), [
            'hotel_csv' => UploadedFile::fake()->createWithContent('hotel.csv', $this->validCsv()),
        ]);
        $import = HotelImport::query()->firstOrFail();
        $this->post(route('program.hotel-rates.imports.commit', $import));
        $regulation = HotelRegulation::query()->firstOrFail();
        $this->post(route('program.hotel-rates.activate', $regulation));

        return [$program, $officer, $employee, $account, $regulation, $province];
    }

    /** @return array{User, User, User, BudgetAccount, Province} */
    private function baseContext(): array
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $employee = User::factory()->create();
        $province = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Makassar',
            'province_id' => $province->id,
            'uang_saku_per_hari' => 370000,
        ]);
        Setting::query()->insert([
            ['nama_setting' => 'batas_hotel', 'nilai_setting' => 500000],
            ['nama_setting' => 'pagu_tiket', 'nilai_setting' => 2000000],
            ['nama_setting' => 'batas_transport_darat', 'nilai_setting' => 1000000],
        ]);
        $account = BudgetAccount::query()->create(['code' => 'MAK-HOTEL', 'is_active' => true]);

        return [$program, $officer, $employee, $account, $province];
    }

    private function sptPayload(
        User $employee,
        BudgetAccount $account,
        string $number,
        string $departure,
        string $return
    ): array {
        return [
            'no_spt' => $number,
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/'.$number,
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employee->id],
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => $departure,
            'tgl_kembali' => $return,
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => $account->code,
        ];
    }

    private function validCsv(bool $aliasesAndReverseOrder = false, ?int $southSulawesiRate = null): string
    {
        $provinces = Province::query()->orderBy('code')->get();
        if ($aliasesAndReverseOrder) {
            $provinces = $provinces->reverse()->values();
        }
        $lines = [implode(',', HotelRateCsvService::HEADERS)];
        foreach ($provinces as $province) {
            $name = $province->name;
            if ($aliasesAndReverseOrder) {
                $name = match ($province->code) {
                    '11' => 'A C E H',
                    '19' => 'BANGKA BELITUNG',
                    '31' => 'D.K.I. JAKARTA',
                    default => $name,
                };
            }
            $amount = match ($province->code) {
                '31' => 730000,
                '73' => $southSulawesiRate ?? 745000,
                default => 600000,
            };
            $lines[] = implode(',', [$name, $amount]);
        }

        return implode("\n", $lines)."\n";
    }
}
