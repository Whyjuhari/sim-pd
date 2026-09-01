<?php

namespace Tests\Feature;

use App\Models\BuktiRealisasi;
use App\Models\PerjalananDinas;
use App\Models\PerjalananDinasStatusHistory;
use App\Models\User;
use App\Services\Documents\PdfConverter;
use App\Services\Reports\TravelRecapExporter;
use App\Services\Reports\TravelRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\TestCase;

class ReportingCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_realization_accepts_multiple_private_evidence_and_scopes_access(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $travel = $this->createTravel($employee, ['foto_bukti' => 'legacy-value.jpg']);
        $this->report($travel);

        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            750000,
            1250000,
            [
                UploadedFile::fake()->image('hotel-1.png', 900, 1200),
                UploadedFile::fake()->image('hotel-2.jpg', 1000, 800),
            ],
            [$this->pdf('tiket.pdf')]
        ))->assertRedirect(route('dashboard.user'));

        $travel->refresh();
        $this->assertSame(PerjalananDinas::STATUS_PENDING, $travel->status);
        $this->assertSame('legacy-value.jpg', $travel->foto_bukti);
        $this->assertDatabaseCount('bukti_realisasi', 3);
        $evidence = BuktiRealisasi::query()->firstOrFail();
        $this->assertStringStartsWith('realization-evidence/', $evidence->path);
        Storage::disk('local')->assertExists($evidence->path);

        $this->get(route('realization-evidence.show', $evidence))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('realization-evidence.show', $evidence))->assertForbidden();
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $this->actingAs($admin)->get(route('realization-evidence.show', $evidence))->assertOk();
        $this->actingAs(User::factory()->role(User::ROLE_VERIFIER)->create())->get(route('realization-evidence.show', $evidence))->assertOk();

        foreach ([User::ROLE_OFFICER, User::ROLE_PROGRAM, User::ROLE_HEAD] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('realization-evidence.show', $evidence))
                ->assertForbidden();
        }

        $storedBeforeRepeat = Storage::disk('local')->allFiles('realization-evidence');
        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            750000,
            1250000,
            [UploadedFile::fake()->image('duplikat.jpg', 800, 800)],
            [$this->pdf('duplikat.pdf')]
        ))->assertNotFound();
        $this->assertSame($storedBeforeRepeat, Storage::disk('local')->allFiles('realization-evidence'));

        $path = $evidence->path;
        $this->actingAs($admin)->post(route('employees.destroy'), ['id' => $employee->id])
            ->assertRedirect(route('employees.index'));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_evidence_is_required_per_positive_category_and_can_be_replaced_on_revision(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $travel = $this->createTravel($employee);
        $this->report($travel);

        $this->actingAs($employee)->post(route('realizations.store'),
            $this->realizationPayload($travel, 500000, 0)
        )->assertSessionHasErrors('items.hotel.evidence');

        $this->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            500000,
            200000,
            [UploadedFile::fake()->image('hotel.jpg', 800, 800)],
            [UploadedFile::fake()->image('transport.jpg', 800, 800)]
        ))->assertRedirect(route('dashboard.user'));

        $this->actingAs($verifier)->post(route('verifications.store'), [
            'id' => $travel->id,
            'action' => 'reject',
            'catatan' => 'Bukti hotel perlu diganti.',
        ])->assertRedirect(route('dashboard.verifier'));

        $hotel = $travel->buktiRealisasi()->where('jenis', BuktiRealisasi::TYPE_HOTEL)->firstOrFail();
        $oldPath = $hotel->path;
        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            500000,
            200000,
            [],
            [],
            ['hapus_bukti' => [$hotel->id]]
        ))->assertSessionHasErrors('items.detail_'.$hotel->realisasi_rincian_id.'.evidence');
        Storage::disk('local')->assertExists($oldPath);

        $this->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            450000,
            200000,
            [UploadedFile::fake()->image('hotel-baru.png', 1000, 900)],
            [],
            ['hapus_bukti' => [$hotel->id]]
        ))->assertRedirect(route('dashboard.user'));

        Storage::disk('local')->assertMissing($oldPath);
        $this->assertDatabaseCount('bukti_realisasi', 2);
        $this->assertSame(PerjalananDinas::STATUS_PENDING, $travel->fresh()->status);
    }

    public function test_unsafe_or_excess_realization_evidence_is_rejected(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create();
        $travel = $this->createTravel($employee);
        $this->report($travel);

        $this->actingAs($employee)->post(route('realizations.store'), $this->realizationPayload(
            $travel, 100, 0, [$this->pdf('rusak.pdf', 'bukan pdf')]
        ))->assertSessionHasErrors('items.hotel.evidence');
        $this->assertDatabaseCount('bukti_realisasi', 0);

        $this->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            100,
            0,
            [UploadedFile::fake()->create('terlalu-besar.pdf', 6000, 'application/pdf')]
        ))->assertSessionHasErrors('items.hotel.evidence.0');

        $this->post(route('realizations.store'), $this->realizationPayload(
            $travel,
            100,
            0,
            [
                UploadedFile::fake()->image('1.jpg', 800, 800),
                UploadedFile::fake()->image('2.jpg', 800, 800),
                UploadedFile::fake()->image('3.jpg', 800, 800),
                UploadedFile::fake()->image('4.jpg', 800, 800),
            ]
        ))->assertSessionHasErrors('items.hotel.evidence');
        $this->assertDatabaseCount('bukti_realisasi', 0);
    }

    public function test_verifier_history_keeps_real_decisions_and_separates_legacy_archive(): void
    {
        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $employee = User::factory()->create();
        $approved = $this->createTravel($employee, ['no_spt' => 'APPROVED/001', 'status' => PerjalananDinas::STATUS_APPROVED]);
        $rejected = $this->createTravel($employee, ['no_spt' => 'REJECTED/001', 'status' => PerjalananDinas::STATUS_REJECTED]);
        $legacy = $this->createTravel($employee, ['no_spt' => 'LEGACY/001', 'status' => PerjalananDinas::STATUS_APPROVED]);
        PerjalananDinasStatusHistory::query()->create([
            'perjalanan_dinas_id' => $approved->id, 'actor_id' => $verifier->id,
            'from_status' => PerjalananDinas::STATUS_PENDING, 'to_status' => PerjalananDinas::STATUS_REJECTED,
            'note' => 'Perbaikan pertama diperlukan.',
        ]);
        PerjalananDinasStatusHistory::query()->create([
            'perjalanan_dinas_id' => $approved->id, 'actor_id' => $verifier->id,
            'from_status' => PerjalananDinas::STATUS_PENDING, 'to_status' => PerjalananDinas::STATUS_APPROVED,
            'note' => 'Disetujui sesuai bukti.',
        ]);
        PerjalananDinasStatusHistory::query()->create([
            'perjalanan_dinas_id' => $rejected->id, 'actor_id' => $verifier->id,
            'from_status' => PerjalananDinas::STATUS_PENDING, 'to_status' => PerjalananDinas::STATUS_REJECTED,
            'note' => 'Mohon perbaiki bukti.',
        ]);

        $this->actingAs($verifier)->get(route('verifications.history'))
            ->assertOk()
            ->assertSeeText('APPROVED/001')
            ->assertSeeText('REJECTED/001')
            ->assertSeeText('Perbaikan pertama diperlukan.')
            ->assertSeeText('Arsip Data Lama')
            ->assertSeeText('LEGACY/001');

        $this->get(route('verifications.history', ['decision' => PerjalananDinas::STATUS_REJECTED]))
            ->assertOk()
            ->assertSeeText('REJECTED/001')
            ->assertDontSeeText('LEGACY/001');
    }

    public function test_employee_history_filters_remain_scoped_to_owner(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $this->createTravel($employee, [
            'no_spt' => 'FILTER/FOUND', 'status' => PerjalananDinas::STATUS_APPROVED,
            'kota_tujuan' => 'Jakarta', 'tgl_berangkat' => '2026-04-10', 'tgl_kembali' => '2026-04-11',
        ]);
        $this->createTravel($employee, [
            'no_spt' => 'FILTER/OTHER-YEAR', 'status' => PerjalananDinas::STATUS_APPROVED,
            'kota_tujuan' => 'Jakarta', 'tgl_berangkat' => '2025-04-10', 'tgl_kembali' => '2025-04-11',
        ]);
        $this->createTravel($employee, ['no_spt' => 'FILTER/OTHER-STATUS', 'kota_tujuan' => 'Jakarta']);
        $this->createTravel($other, [
            'no_spt' => 'FILTER/PRIVATE', 'status' => PerjalananDinas::STATUS_APPROVED,
            'kota_tujuan' => 'Jakarta', 'tgl_berangkat' => '2026-04-10', 'tgl_kembali' => '2026-04-11',
        ]);

        $this->actingAs($employee)->get(route('dashboard.user', [
            'status' => PerjalananDinas::STATUS_APPROVED,
            'year' => 2026,
            'destination' => 'Jakarta',
            'spt' => 'FILTER',
        ]))->assertOk()
            ->assertSeeText('FILTER/FOUND')
            ->assertDontSeeText('FILTER/OTHER-YEAR')
            ->assertDontSeeText('FILTER/OTHER-STATUS')
            ->assertDontSeeText('FILTER/PRIVATE');
    }

    public function test_recap_service_and_workbooks_match_role_scope(): void
    {
        $employee = User::factory()->create();
        $this->createTravel($employee, [
            'no_spt' => 'REKAP/1', 'status' => PerjalananDinas::STATUS_APPROVED,
            'tgl_berangkat' => '2026-08-01', 'tgl_kembali' => '2026-08-02',
            'kota_tujuan' => 'Jakarta', 'akun_anggaran' => 'MAK-A',
            'estimasi_biaya' => 1000, 'total_cair' => 800,
        ]);
        $this->createTravel($employee, [
            'no_spt' => 'REKAP/2', 'status' => PerjalananDinas::STATUS_PENDING,
            'tgl_berangkat' => '2026-09-01', 'tgl_kembali' => '2026-09-02',
            'kota_tujuan' => 'Makassar', 'akun_anggaran' => 'MAK-B',
            'estimasi_biaya' => 2000, 'total_cair' => 500,
        ]);
        $recap = app(TravelRecapService::class);
        $query = $recap->headQuery(2026);
        $summary = $recap->summary($query);
        $groups = $recap->grouped($query, 2026);

        $this->assertSame(2, $summary['count']);
        $this->assertSame(3000.0, $summary['estimate']);
        $this->assertSame(800.0, $summary['realized']);
        $this->assertCount(12, $groups['months']);
        $this->assertSame(800.0, $groups['accounts']->firstWhere('key', 'MAK-A')['realized']);
        $programFiltered = $recap->programQuery(['account' => 'MAK-A']);
        $this->assertSame(1, $recap->summary($programFiltered)['count']);

        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $this->actingAs($program)->get(route('dashboard.program', ['account' => 'MAK-A']))
            ->assertOk()
            ->assertSeeText('Jakarta')
            ->assertSee('<option selected>MAK-A</option>', false)
            ->assertDontSee(route('program.recap.export', ['format' => 'xlsx']), false)
            ->assertDontSee(route('program.recap.export', ['format' => 'pdf']), false);

        $exporter = app(TravelRecapExporter::class);
        $programPath = $exporter->generate('program', $query, $summary, $groups, [
            'period' => '2026', 'period_label' => 'Tahun 2026',
        ]);
        $headPath = $exporter->generate('pimpinan', $query, $summary, $groups, [
            'period' => '2026', 'period_label' => 'Tahun 2026',
        ]);

        try {
            $programWorkbook = IOFactory::load($programPath);
            $headWorkbook = IOFactory::load($headPath);
            $this->assertNotNull($programWorkbook->getSheetByName('Detail Transaksi'));
            $this->assertNull($headWorkbook->getSheetByName('Detail Transaksi'));
            $this->assertNotNull($headWorkbook->getSheetByName('Ringkasan Eksekutif'));
            $programWorkbook->disconnectWorksheets();
            $headWorkbook->disconnectWorksheets();
        } finally {
            @unlink($programPath);
            @unlink($headPath);
        }
    }

    public function test_recap_routes_are_role_scoped_and_pdf_errors_are_sanitized(): void
    {
        $program = User::factory()->role(User::ROLE_PROGRAM)->create();
        $head = User::factory()->role(User::ROLE_HEAD)->create();
        $employee = User::factory()->create();

        $temporaryExports = [];

        try {
            $programExport = $this->actingAs($program)
                ->get(route('program.recap.export', ['format' => 'xlsx']))
                ->assertOk();
            $temporaryExports[] = $programExport->baseResponse->getFile()->getPathname();

            $this->actingAs($employee)
                ->get(route('program.recap.export', ['format' => 'xlsx']))
                ->assertForbidden();

            $headExport = $this->actingAs($head)
                ->get(route('head.recap.export', ['format' => 'xlsx', 'year' => 2026]))
                ->assertOk();
            $temporaryExports[] = $headExport->baseResponse->getFile()->getPathname();

            $converter = \Mockery::mock(PdfConverter::class);
            $converter->shouldReceive('convert')->once()->andThrow(new RuntimeException('SECRET_SERVER_PATH'));
            $this->app->instance(PdfConverter::class, $converter);
            $this->get(route('head.recap.export', ['format' => 'pdf', 'year' => 2026]))
                ->assertServerError()
                ->assertDontSee('SECRET_SERVER_PATH');
        } finally {
            foreach ($temporaryExports as $temporaryExport) {
                if (is_string($temporaryExport) && is_file($temporaryExport)) {
                    unlink($temporaryExport);
                }
            }
        }
    }

    public function test_navigation_badges_show_only_actionable_work(): void
    {
        $employee = User::factory()->create();
        $this->createTravel($employee, ['no_spt' => 'BADGE/1']);
        $this->createTravel($employee, ['no_spt' => 'BADGE/2', 'status' => PerjalananDinas::STATUS_REJECTED]);
        $this->createTravel($employee, ['no_spt' => 'BADGE/3', 'status' => PerjalananDinas::STATUS_APPROVED]);

        $employeePage = $this->actingAs($employee)->get(route('dashboard.user'))->assertOk();
        $this->assertSame(2, substr_count($employeePage->getContent(), '2 tugas memerlukan tindakan'));

        for ($index = 0; $index < 98; $index++) {
            $this->createTravel($employee, ['no_spt' => 'BADGE/MANY/'.$index]);
        }
        $largeBadgePage = $this->get(route('dashboard.user'))->assertOk();
        $this->assertSame(2, substr_count($largeBadgePage->getContent(), '>99+<'));

        $verifier = User::factory()->role(User::ROLE_VERIFIER)->create();
        $this->createTravel(User::factory()->create(), ['no_spt' => 'BADGE/PENDING', 'status' => PerjalananDinas::STATUS_PENDING]);
        $verifierPage = $this->actingAs($verifier)->get(route('dashboard.verifier'))->assertOk();
        $this->assertSame(2, substr_count($verifierPage->getContent(), '1 pengajuan menunggu verifikasi'));
    }

    private function createTravel(User $employee, array $attributes = []): PerjalananDinas
    {
        return PerjalananDinas::query()->create([
            'no_spt' => 'TEST/'.fake()->unique()->numerify('#####'),
            'menimbang' => 'Kebutuhan dinas',
            'no_memo' => 'MEMO/'.fake()->unique()->numerify('#####'),
            'perihal_memo' => 'Koordinasi',
            'user_id' => $employee->id,
            'maksud_perjalanan' => 'Melaksanakan koordinasi',
            'kota_tujuan' => 'Makassar',
            'tempat_berangkat' => 'Pangkep',
            'tgl_berangkat' => '2026-08-10',
            'tgl_kembali' => '2026-08-11',
            'lama_hari' => 2,
            'angkutan' => 'Transportasi Darat',
            'akun_anggaran' => '4053.PDI.002.054.B.524111',
            'estimasi_biaya' => 1000000,
            'status' => PerjalananDinas::STATUS_READY,
            ...$attributes,
        ]);
    }

    private function report(PerjalananDinas $travel): void
    {
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan terlaksana.',
            'kesimpulan' => 'Tujuan tercapai.',
            'tanggal_laporan' => '2026-08-12',
        ]);
    }

    private function pdf(string $name, ?string $content = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            $content ?? "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF"
        );
    }
}
