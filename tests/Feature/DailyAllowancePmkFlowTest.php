<?php

namespace Tests\Feature;

use App\Models\BudgetAccount;
use App\Models\DailyAllowanceImport;
use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Models\Setting;
use App\Models\User;
use App\Services\DailyAllowanceCsvService;
use App\Services\TravelCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyAllowancePmkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_can_download_the_locked_csv_template(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();

        $response = $this->actingAs($program)->get(route('program.daily-allowances.template'));

        $response->assertOk();
        $response->assertDownload('Template_Uang_Harian_PMK_32_2025.csv');
        $content = $response->streamedContent();
        $this->assertStringContainsString(
            'kode_provinsi,nama_provinsi,luar_kota,dalam_kota_lebih_8_jam,diklat',
            $content
        );
        $this->assertStringContainsString('73,"SULAWESI SELATAN"', $content);

        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $downloadedCodes = collect(array_slice($lines, 1))
            ->map(fn(string $line): string => str_getcsv($line, ',')[0])
            ->all();
        $this->assertSame(DailyAllowanceCsvService::PDF_PROVINCE_ORDER, $downloadedCodes);
    }

    public function test_invalid_csv_is_rejected_without_creating_an_import(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $file = UploadedFile::fake()->createWithContent(
            'tarif.csv',
            "kode_provinsi,nama_provinsi,luar_kota\n73,SULAWESI SELATAN,430000\n"
        );

        $this->actingAs($program)
            ->post(route('program.daily-allowances.imports.upload'), ['csv' => $file])
            ->assertSessionHasErrors('csv');

        $this->assertDatabaseCount('daily_allowance_imports', 0);
        Storage::disk('local')->assertDirectoryEmpty('pmk/daily-allowance/imports/temporary');
    }

    public function test_excel_semicolon_csv_is_accepted_and_keeps_pdf_row_order_in_preview(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $semicolonCsv = str_replace(',', ';', $this->validCsvInPdfOrder());

        $this->actingAs($program)
            ->post(route('program.daily-allowances.imports.upload'), [
                'csv' => UploadedFile::fake()->createWithContent('uang-harian.csv', $semicolonCsv),
            ])->assertRedirect();

        $import = DailyAllowanceImport::query()->firstOrFail();
        $this->assertSame(
            DailyAllowanceCsvService::PDF_PROVINCE_ORDER,
            collect($import->parsed_rows)->pluck('kode_provinsi')->all()
        );
    }

    public function test_daily_allowance_import_routes_are_restricted_to_program_role(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)
            ->get(route('program.daily-allowances.template'))
            ->assertForbidden();
        $this->post(route('program.daily-allowances.imports.upload'), [
            'csv' => UploadedFile::fake()->createWithContent('uang-harian.csv', $this->validCsv()),
        ])->assertForbidden();

        $this->assertDatabaseCount('daily_allowance_imports', 0);
    }

    public function test_program_uploads_previews_commits_and_activates_a_private_dataset(): void
    {
        Storage::fake('local');
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $southSulawesi = Province::query()->where('code', '73')->firstOrFail();
        MasterTarif::query()->create([
            'kota_tujuan' => 'Makassar',
            'province_id' => $southSulawesi->id,
            'uang_saku_per_hari' => 370000,
        ]);

        $this->actingAs($program)
            ->post(route('program.daily-allowances.imports.upload'), [
                'csv' => UploadedFile::fake()->createWithContent('uang-harian.csv', $this->validCsv()),
            ])->assertRedirect();

        $import = DailyAllowanceImport::query()->firstOrFail();
        $this->assertSame(DailyAllowanceImport::STATUS_VALIDATED, $import->status);
        $this->get(route('program.daily-allowances.imports.preview', $import))
            ->assertOk()
            ->assertSeeText('38 provinsi valid')
            ->assertSeeText('SULAWESI SELATAN')
            ->assertSeeText('Rp 430.000');

        $this->post(route('program.daily-allowances.imports.commit', $import))
            ->assertRedirect(route('program.budget.edit'))
            ->assertSessionHasNoErrors();

        $regulation = DailyAllowanceRegulation::query()->firstOrFail();
        $this->assertSame(DailyAllowanceRegulation::STATUS_DRAFT, $regulation->status);
        $this->assertSame(38, $regulation->rates()->count());
        Storage::disk('local')->assertExists($regulation->csv_path);
        Storage::disk('local')->assertMissing((string) $import->temporary_path);

        $this->post(route('program.daily-allowances.activate', $regulation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $regulation->refresh();
        $this->assertSame(DailyAllowanceRegulation::STATUS_ACTIVE, $regulation->status);
        $this->assertSame($program->id, $regulation->activated_by);
    }

    public function test_new_spt_uses_active_pmk_rate_while_legacy_snapshot_remains_supported(): void
    {
        Storage::fake('local');
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
        $account = BudgetAccount::query()->create(['code' => 'MAK-PMK', 'is_active' => true]);

        $this->actingAs($program)->post(route('program.daily-allowances.imports.upload'), [
            'csv' => UploadedFile::fake()->createWithContent('uang-harian.csv', $this->validCsv()),
        ]);
        $import = DailyAllowanceImport::query()->firstOrFail();
        $this->post(route('program.daily-allowances.imports.commit', $import));
        $regulation = DailyAllowanceRegulation::query()->firstOrFail();
        $this->post(route('program.daily-allowances.activate', $regulation));

        $this->actingAs($officer)->post(route('travel-orders.store'), [
            'no_spt' => 'PMK/001',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/PMK/001',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employee->id],
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-11',
            'angkutan' => 'Pesawat Udara',
            'daily_allowance_category' => DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
            'akun_anggaran' => $account->code,
        ])->assertRedirect(route('dashboard.officer'));

        $travel = PerjalananDinas::query()->firstOrFail();
        $this->assertSame('430000.00', $travel->uang_harian_per_hari_snapshot);
        $this->assertSame('pmk', $travel->daily_allowance_source);
        $this->assertSame(DailyAllowanceRate::CATEGORY_OUTSIDE_CITY, $travel->daily_allowance_category);
        $this->assertSame($regulation->id, $travel->daily_allowance_regulation_id);
        $this->assertSame($province->id, $travel->daily_allowance_province_id);
    }

    public function test_inside_city_over_eight_hours_is_limited_to_one_day(): void
    {
        Storage::fake('local');
        [$program, $officer, $employee, $account, $regulation] = $this->activeDatasetContext();

        $this->actingAs($officer)->post(route('travel-orders.store'), [
            'no_spt' => 'PMK/INVALID',
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/PMK/INVALID',
            'perihal_memo' => 'Koordinasi',
            'tgl_memo' => '2026-08-01',
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'user_ids' => [$employee->id],
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-11',
            'angkutan' => 'Transportasi Darat',
            'daily_allowance_category' => DailyAllowanceRate::CATEGORY_INSIDE_CITY_OVER_8_HOURS,
            'akun_anggaran' => $account->code,
        ])->assertSessionHasErrors('daily_allowance_category');

        $this->assertDatabaseCount('transaksi_perjadin', 0);
        $this->assertSame(DailyAllowanceRegulation::STATUS_ACTIVE, $regulation->fresh()->status);
        $this->assertSame(User::ROLE_PROGRAM, $program->role);
    }

    public function test_existing_legacy_spt_keeps_legacy_rate_after_pmk_activation(): void
    {
        Storage::fake('local');
        [, , $employee] = $this->activeDatasetContext();
        $legacy = new PerjalananDinas([
            'user_id' => $employee->id,
            'kota_tujuan' => 'Makassar',
            'tgl_berangkat' => '2026-08-10',
            'daily_allowance_source' => null,
            'uang_harian_per_hari_snapshot' => 370000,
        ]);

        $rates = app(TravelCostCalculator::class)->ratesForOrder(
            'Makassar',
            'Transportasi Darat',
            '2026-08-10',
            DailyAllowanceRate::CATEGORY_OUTSIDE_CITY,
            $legacy
        );

        $this->assertSame('legacy', $rates['daily_allowance_source']);
        $this->assertSame(370000.0, $rates['daily_allowance']);
        $this->assertNull($rates['daily_allowance_regulation_id']);
    }

    /** @return array{User, User, User, BudgetAccount, DailyAllowanceRegulation} */
    private function activeDatasetContext(): array
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
        $account = BudgetAccount::query()->create(['code' => 'MAK-PMK', 'is_active' => true]);
        $this->actingAs($program)->post(route('program.daily-allowances.imports.upload'), [
            'csv' => UploadedFile::fake()->createWithContent('uang-harian.csv', $this->validCsv()),
        ]);
        $import = DailyAllowanceImport::query()->firstOrFail();
        $this->post(route('program.daily-allowances.imports.commit', $import));
        $regulation = DailyAllowanceRegulation::query()->firstOrFail();
        $this->post(route('program.daily-allowances.activate', $regulation));

        return [$program, $officer, $employee, $account, $regulation];
    }

    private function validCsv(): string
    {
        $rates = [
            '11' => [360000, 140000, 110000], '12' => [370000, 150000, 110000],
            '13' => [380000, 150000, 110000], '14' => [370000, 150000, 110000],
            '15' => [370000, 150000, 110000], '16' => [380000, 150000, 110000],
            '17' => [380000, 150000, 110000], '18' => [380000, 150000, 110000],
            '19' => [410000, 160000, 120000], '21' => [370000, 150000, 110000],
            '31' => [530000, 210000, 160000], '32' => [430000, 170000, 130000],
            '33' => [370000, 150000, 110000], '34' => [420000, 170000, 130000],
            '35' => [410000, 160000, 120000], '36' => [370000, 150000, 110000],
            '51' => [480000, 190000, 140000], '52' => [440000, 180000, 130000],
            '53' => [430000, 170000, 130000], '61' => [380000, 150000, 110000],
            '62' => [360000, 140000, 110000], '63' => [380000, 150000, 110000],
            '64' => [430000, 170000, 130000], '65' => [430000, 170000, 130000],
            '71' => [370000, 150000, 110000], '72' => [370000, 150000, 110000],
            '73' => [430000, 170000, 130000], '74' => [380000, 150000, 110000],
            '75' => [370000, 150000, 110000], '76' => [410000, 160000, 120000],
            '81' => [380000, 150000, 110000], '82' => [430000, 170000, 130000],
            '91' => [480000, 190000, 140000], '92' => [480000, 190000, 140000],
            '94' => [580000, 230000, 170000], '95' => [580000, 230000, 170000],
            '96' => [580000, 230000, 170000], '97' => [580000, 230000, 170000],
        ];

        $lines = [implode(',', DailyAllowanceCsvService::HEADERS)];
        foreach (Province::query()->orderBy('code')->get() as $province) {
            $lines[] = implode(',', [$province->code, $province->name, ...$rates[$province->code]]);
        }

        return implode("\n", $lines)."\n";
    }

    private function validCsvInPdfOrder(): string
    {
        $rowsByCode = collect(explode("\n", trim($this->validCsv())))
            ->skip(1)
            ->mapWithKeys(function (string $line): array {
                $columns = str_getcsv($line, ',');

                return [$columns[0] => $line];
            });

        return implode("\n", [
            implode(',', DailyAllowanceCsvService::HEADERS),
            ...collect(DailyAllowanceCsvService::PDF_PROVINCE_ORDER)
                ->map(fn(string $code): string => $rowsByCode[$code])
                ->all(),
        ])."\n";
    }
}
