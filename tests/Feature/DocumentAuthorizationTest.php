<?php

namespace Tests\Feature;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/private/documents/{pdf,temporary}/*'), GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_travel_document_policy_allows_only_expected_roles(): void
    {
        $owner = User::factory()->create();
        $travel = $this->createTravel($owner, PerjalananDinas::STATUS_APPROVED);

        $allowedUsers = [
            $owner,
            User::factory()->role(User::ROLE_ADMIN)->create(),
            User::factory()->role(User::ROLE_VERIFIER)->create(),
        ];

        foreach ($allowedUsers as $user) {
            $this->assertTrue(
                Gate::forUser($user)->allows('printTravelDocument', $travel),
                "Role {$user->role} seharusnya dapat mencetak dokumen perjalanan yang sudah disetujui."
            );
        }

        $deniedUsers = [
            User::factory()->role(User::ROLE_OFFICER)->create(),
            User::factory()->role(User::ROLE_PROGRAM)->create(),
            User::factory()->role(User::ROLE_HEAD)->create(),
            User::factory()->role(User::ROLE_USER)->create(),
        ];

        foreach ($deniedUsers as $user) {
            $this->assertFalse(
                Gate::forUser($user)->allows('printTravelDocument', $travel),
                "Role {$user->role} tidak seharusnya dapat mencetak dokumen perjalanan ini."
            );
        }
    }

    public function test_travel_document_endpoint_enforces_role_ownership_and_status(): void
    {
        $owner = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $travel = $this->createTravel($owner, PerjalananDinas::STATUS_APPROVED);
        $url = route('documents.perjadin', ['id' => $travel->id]);

        foreach ([User::ROLE_OFFICER, User::ROLE_PROGRAM, User::ROLE_HEAD] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $this->actingAs($otherEmployee)->get($url)->assertNotFound();

        $travel->update(['status' => PerjalananDinas::STATUS_READY]);
        $this->actingAs($owner)->get($url)->assertForbidden();
    }

    public function test_surat_tugas_policy_preserves_officer_and_group_member_access(): void
    {
        $owner = User::factory()->create();
        $groupMember = User::factory()->create();
        $otherEmployee = User::factory()->create();
        $officer = User::factory()->role(User::ROLE_OFFICER)->create();
        $groupId = (string) Str::uuid();

        $travel = $this->createTravel(
            $owner,
            PerjalananDinas::STATUS_READY,
            ['spt_group_id' => $groupId]
        );

        $this->createTravel(
            $groupMember,
            PerjalananDinas::STATUS_READY,
            ['spt_group_id' => $groupId, 'no_spt' => $travel->no_spt]
        );

        $this->assertTrue(Gate::forUser($officer)->allows('printSuratTugas', $travel));
        $this->assertTrue(Gate::forUser($owner)->allows('printSuratTugas', $travel));
        $this->assertTrue(Gate::forUser($groupMember)->allows('printSuratTugas', $travel));
        $this->assertFalse(Gate::forUser($otherEmployee)->allows('printSuratTugas', $travel));
        $this->assertFalse(
            Gate::forUser(User::factory()->role(User::ROLE_ADMIN)->create())
                ->allows('printSuratTugas', $travel)
        );
    }

    public function test_owner_can_render_approved_travel_document(): void
    {
        $this->skipWhenLibreOfficeIsUnavailable();

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner, PerjalananDinas::STATUS_APPROVED);

        $this->actingAs($owner)
            ->get(route('documents.perjadin', ['id' => $travel->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_owner_can_render_laporan_perjadin_using_configured_template(): void
    {
        $this->skipWhenLibreOfficeIsUnavailable();

        $templatePath = config('sim_pd.documents.templates.laporan_perjadin');
        $this->assertIsString($templatePath);
        $this->assertFileExists($templatePath);

        Storage::fake('local');

        $owner = User::factory()->create();
        $signaturePath = 'signatures/report-test.png';
        Storage::disk('local')->put(
            $signaturePath,
            file_get_contents(public_path('assets/images/logo.png'))
        );
        $owner->update(['ttd_path' => $signaturePath]);

        $travel = $this->createTravel($owner, PerjalananDinas::STATUS_APPROVED);
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan perjalanan dinas terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan dinas telah tercapai.',
            'tanggal_laporan' => '2026-08-20',
        ]);

        $this->actingAs($owner)
            ->get(route('documents.laporan-perjadin', ['id' => $travel->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_report_without_signature_returns_to_dashboard_with_clear_message(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $travel = $this->createTravel($owner, PerjalananDinas::STATUS_APPROVED);
        $travel->laporan()->create([
            'hasil_pelaksanaan' => 'Kegiatan perjalanan dinas terlaksana dengan baik.',
            'kesimpulan' => 'Tujuan perjalanan dinas telah tercapai.',
            'tanggal_laporan' => '2026-08-20',
        ]);

        $documentUrl = route('documents.laporan-perjadin', ['id' => $travel->id]);

        $this->actingAs($owner)
            ->get($documentUrl)
            ->assertRedirect(route('dashboard.user'))
            ->assertSessionHas(
                'warning',
                'Laporan belum dapat dicetak karena tanda tangan Anda belum tersedia. Silakan hubungi Admin.'
            );

        $this->actingAs($owner)
            ->get(route('dashboard.user'))
            ->assertOk()
            ->assertSee('Tanda tangan belum tersedia. Hubungi Admin.')
            ->assertDontSee($documentUrl, false);
    }

    private function createTravel(
        User $owner,
        string $status,
        array $overrides = []
    ): PerjalananDinas {
        MasterTarif::query()->firstOrCreate(
            ['kota_tujuan' => 'Makassar'],
            ['uang_saku_per_hari' => 370000]
        );

        return PerjalananDinas::query()->create([
            'spt_group_id' => (string) Str::uuid(),
            'no_spt' => 'DOC/AUTH/001/VIII/2026',
            'menimbang' => 'Kebutuhan pelaksanaan tugas',
            'no_memo' => 'MEMO/AUTH/001',
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
            'biaya_hotel_real' => 500000,
            'biaya_tiket_real' => 1800000,
            'biaya_hotel_approved' => 500000,
            'total_cair' => 3410000,
            'status' => $status,
            ...$overrides,
        ]);
    }

    private function skipWhenLibreOfficeIsUnavailable(): void
    {
        if (! is_file(config('sim_pd.documents.libreoffice.binary'))) {
            $this->markTestSkipped('LibreOffice tidak tersedia.');
        }
    }
}
