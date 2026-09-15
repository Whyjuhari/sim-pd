<?php

namespace App\Http\Controllers;

use App\Support\OfficerSptNavigation;

use App\Models\MasterTarif;
use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRate;
use App\Models\DipaSetting;
use App\Models\PerjalananDinas;
use App\Models\SptSrikandiVersion;
use App\Models\SptSrikandiWorkflow;
use App\Models\SptTemplate;
use App\Models\User;
use App\Services\TravelCostCalculator;
use App\Services\Documents\DocumentCachePrewarmer;
use App\Services\Documents\TravelPdfDocumentService;
use App\Services\Uploads\SptSrikandiDocumentStorage;
use App\Support\SptTemplateVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use DomainException;

class TravelOrderController extends Controller
{
    public function __construct(
        private readonly TravelCostCalculator $calculator,
        private readonly DocumentCachePrewarmer $documentPrewarmer,
        private readonly SptSrikandiDocumentStorage $srikandiStorage,
    ) {}
    public function create(Request $request): View
    {
        $selectedTemplate = $this->resolveSelectedTemplate($request);

        if ($selectedTemplate === null) {
            return view('travel.template-select', [
                'templates' => SptTemplate::query()
                    ->withCount('perjalananDinas')
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('nama')
                    ->get(),
                'builtInTemplates' => SptTemplateVariant::all(),
            ]);
        }

        return view('travel.create', $this->createFormData($selectedTemplate));
    }

    /** @return array{type: string, id: ?int, variant: ?string, label: string, uses_memo: bool, uses_dipa: bool}|null */
    private function resolveSelectedTemplate(Request $request): ?array
    {
        $raw = trim((string) $request->query('template', ''));

        if ($raw === '') {
            return null;
        }

        if ($raw === 'system') {
            return [
                'type' => 'system',
                'id' => null,
                'variant' => null,
                'label' => 'Template Sistem (Legacy)',
                'uses_memo' => true,
                'uses_dipa' => false,
            ];
        }

        if (str_starts_with($raw, 'variant:')) {
            $key = substr($raw, strlen('variant:'));
            $variant = SptTemplateVariant::get($key);

            if (! $variant) {
                return null;
            }

            return [
                'type' => 'variant',
                'id' => null,
                'variant' => $key,
                'label' => $variant['label'],
                'uses_memo' => $variant['uses_memo'],
                'uses_dipa' => $variant['uses_dipa'],
            ];
        }

        if (! ctype_digit($raw)) {
            return null;
        }

        $template = SptTemplate::query()
            ->whereKey((int) $raw)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return null;
        }

        return [
            'type' => 'custom',
            'id' => (int) $template->id,
            'variant' => null,
            'label' => $template->nama,
            'uses_memo' => true,
            'uses_dipa' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function createFormData(array $selectedTemplate): array
    {
        return [
            'selectedTemplate' => $selectedTemplate,
            'employees' => User::query()
                ->where('role', User::ROLE_USER)
                ->orderBy('nama_lengkap')
                ->get(['id', 'nama_lengkap', 'nip', 'jabatan']),

            'destinations' => MasterTarif::query()
                ->with('province:id,name')
                ->orderBy('kota_tujuan')
                ->get(['id', 'kota_tujuan', 'province_id']),

            'budgetAccounts' => BudgetAccount::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'dailyAllowanceCategories' => DailyAllowanceRate::categoryLabels(),
        ];
    }

    public function store(
        Request $request
    ): RedirectResponse {

        $data = $this->validatedData($request);


        [$days, $estimate, $rates] =
            $this->calculateTrip($data);


        $sptGroupId =
            (string) Str::uuid();

        $dipaSnapshot = $this->resolveDipaSnapshot($data);
        $internalReference = $this->generateInternalReference((int) CarbonImmutable::parse($data['tgl_berangkat'])->year);

        $commonData =
            $this->commonTravelData(
                $data,
                $days,
                $estimate,
                $rates,
                $dipaSnapshot,
                [
                    'spt_internal_reference' => $internalReference,
                    'spt_number_mode' => $data['spt_number_mode'],
                    'spt_external_number' => null,
                    'spt_external_number_recorded_at' => null,
                    'spt_external_number_recorded_by' => null,
                ]
            );

        $initialStatus = $data['spt_number_mode'] === PerjalananDinas::NUMBER_MODE_EXTERNAL
            ? PerjalananDinas::STATUS_DRAFT
            : PerjalananDinas::STATUS_READY;

        DB::transaction(
            function () use (
                $data,
                $sptGroupId,
                $commonData,
                $request,
                $initialStatus
            ): void {
                foreach (
                    $data['user_ids']
                    as $userId
                ) {
                    PerjalananDinas::query()
                        ->create([
                            'spt_group_id'
                            => $sptGroupId,

                            'user_id'
                            => (int) $userId,

                            'created_by'
                            => (int) $request->user()->id,

                            ...$commonData,

                            'status'
                            => $initialStatus,
                        ]);
                }

                if ($data['spt_number_mode'] === PerjalananDinas::NUMBER_MODE_EXTERNAL) {
                    SptSrikandiWorkflow::query()->create([
                        'spt_group_id' => $sptGroupId,
                        'status' => SptSrikandiWorkflow::STATUS_DRAFT,
                    ]);
                }
            }
        );

        $referenceId = PerjalananDinas::query()
            ->where('spt_group_id', $sptGroupId)
            ->min('id');
        if ($referenceId) {
            $this->documentPrewarmer->afterResponse(
                TravelPdfDocumentService::TYPE_SPT,
                (int) $referenceId
            );
        }

        $successMessage = $data['spt_number_mode'] === PerjalananDinas::NUMBER_MODE_EXTERNAL
            ? 'SPT berhasil disimpan sebagai konsep dan belum tersedia untuk Pegawai. Periksa konsep, lalu unduh file Word untuk melanjutkan.'
            : 'SPT berhasil disimpan dan siap digunakan.';

        return redirect()
            ->route(
                $data['spt_number_mode'] === PerjalananDinas::NUMBER_MODE_EXTERNAL ? 'travel-orders.show' : 'dashboard.officer',
                $data['spt_number_mode'] === PerjalananDinas::NUMBER_MODE_EXTERNAL ? ['sptGroupId' => $sptGroupId] : []
            )
            ->with(
                'success',
                $successMessage
            );
    }

    public function previewCost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kota_tujuan' => ['required', 'string', 'max:50', 'exists:master_tarif,kota_tujuan'],
            'tempat_berangkat' => ['required', 'string', 'max:50'],
            'tgl_berangkat' => ['required', 'date'],
            'tgl_kembali' => ['required', 'date', 'after_or_equal:tgl_berangkat'],
            'angkutan' => ['required', Rule::in(['Pesawat Udara', 'Transportasi Darat'])],
            'daily_allowance_category' => ['nullable', Rule::in(array_keys(DailyAllowanceRate::categoryLabels()))],
            'spt_group_id' => ['nullable', 'uuid'],
        ]);

        $existingTravel = null;
        if (! empty($data['spt_group_id'])) {
            $travels = $this->groupTravels($data['spt_group_id']);
            if (! $this->canModify($travels)) {
                throw ValidationException::withMessages([
                    'spt_group_id' => 'SPT ini tidak dapat dihitung ulang karena proses perjalanan sudah berjalan.',
                ]);
            }
            $existingTravel = $travels->first();
        }

        [$days, $estimate, $rates] = $this->calculateTrip($data, $existingTravel);

        return response()->json([
            'days' => $days,
            'total' => $estimate,
            'components' => $this->costPreviewComponents($rates, $days),
            'has_fallback' => collect([
                $rates['daily_allowance_source'] ?? null,
                $rates['hotel_rate_source'] ?? null,
                $rates['transport_rate_source'] ?? null,
                $rates['terminal_origin_source'] ?? null,
                $rates['terminal_destination_source'] ?? null,
                $rates['airfare_rate_source'] ?? null,
            ])->contains(fn($source): bool => in_array($source, ['legacy', 'unavailable'], true)),
        ]);
    }
    public function show(
        Request $request,
        string $sptGroupId
    ): View {
        $travels =
            $this->groupTravels(
                $sptGroupId
            );

        $travel =
            $travels->first();

        $canModify =
            $this->canModify(
                $travels
            );

        $workflow = SptSrikandiWorkflow::query()
            ->with(['versions.preparer', 'versions.submitter', 'versions.revisionRequester', 'publisher', 'uploader'])
            ->where('spt_group_id', $sptGroupId)
            ->first();

        $preparedVersion = $workflow?->versions->sortByDesc('version_number')
            ->first(fn ($version) => $version->submitted_at === null);
        $conceptReady = $preparedVersion && $this->srikandiStorage->verifiedConceptAbsolutePath(
            $preparedVersion->docx_path, $preparedVersion->docx_sha256
        );

        return view(
            'travel.show',
            [
                'travel' => $travel,
                'travels' => $travels,
                'canModify' => $canModify,
                'canDelete' => $this->canDelete($travels),
                'srikandiWorkflow' => $workflow,
                'detailContext' => OfficerSptNavigation::context($request),
                'detailBackRoute' => OfficerSptNavigation::backUrl($request, $workflow && $workflow->status !== SptSrikandiWorkflow::STATUS_PUBLISHED),
                'conceptReady' => (bool) $conceptReady,
                'preparedVersion' => $preparedVersion,
                'canRecordSrikandiNumber' => ! $workflow
                    && $travel->spt_number_mode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                    && empty($travel->spt_external_number),
            ]
        );
    }

    public function recordSrikandiNumber(Request $request, string $sptGroupId): RedirectResponse
    {
        $data = $request->validate([
            'spt_external_number' => [
                'required',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) === PerjalananDinas::NUMBER_PLACEHOLDER) {
                        $fail('Nomor Srikandi tidak boleh menggunakan parameter nomor naskah.');
                    }
                },
            ],
        ]);
        $externalNumber = trim($data['spt_external_number']);

        DB::transaction(function () use ($sptGroupId, $externalNumber, $request): void {
            $workflow = SptSrikandiWorkflow::query()
                ->where('spt_group_id', $sptGroupId)
                ->lockForUpdate()
                ->first();
            if ($workflow) {
                throw ValidationException::withMessages([
                    'spt_external_number' => 'Nomor untuk alur Srikandi baru dicatat bersama unggahan PDF resmi.',
                ]);
            }

            $travels = PerjalananDinas::query()
                ->where('spt_group_id', $sptGroupId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($travels->isEmpty(), 404, 'Data Surat Tugas tidak ditemukan.');

            if (! $travels->every(fn (PerjalananDinas $travel): bool =>
                $travel->spt_number_mode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                && empty($travel->spt_external_number)
            )) {
                throw ValidationException::withMessages([
                    'spt_external_number' => 'Nomor Srikandi untuk SPT ini sudah pernah dicatat.',
                ]);
            }

            $alreadyUsed = PerjalananDinas::query()
                ->where(function ($query) use ($externalNumber): void {
                    $query->where('spt_external_number', $externalNumber)
                        ->orWhere(function ($query) use ($externalNumber): void {
                            $query->where('spt_number_mode', PerjalananDinas::NUMBER_MODE_MANUAL)
                                ->where('no_spt', $externalNumber);
                        });
                })
                ->where(function ($query) use ($sptGroupId): void {
                    $query->whereNull('spt_group_id')
                        ->orWhere('spt_group_id', '!=', $sptGroupId);
                })
                ->lockForUpdate()
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'spt_external_number' => 'Nomor Srikandi sudah digunakan oleh SPT lain.',
                ]);
            }

            PerjalananDinas::query()
                ->where('spt_group_id', $sptGroupId)
                ->update([
                    'spt_external_number' => $externalNumber,
                    'spt_external_number_recorded_at' => now(),
                    'spt_external_number_recorded_by' => (int) $request->user()->id,
                ]);
        });

        return redirect()
            ->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request))
            ->with('success', 'Nomor Srikandi berhasil dicatat tanpa mengubah isi Surat Tugas.');
    }


    public function edit(
        Request $request,
        string $sptGroupId
    ): View|RedirectResponse {
        $travels =
            $this->groupTravels(
                $sptGroupId
            );


        if (! $this->canModify($travels)) {
            return redirect()
                ->route(
                    'travel-orders.show',
                    [
                        'sptGroupId'
                        => $sptGroupId,
                    ] + OfficerSptNavigation::context($request)
                )
                ->withErrors([
                    'spt' =>
                    'Surat Tugas tidak dapat diedit karena proses perjalanan salah satu pegawai sudah berjalan.',
                ]);
        }

        return view(
            'travel.edit',
            [
                'srikandiWorkflow' => $travels->first()->sptSrikandiWorkflow,
                'detailContext' => OfficerSptNavigation::context($request),
                'travel'
                => $travels->first(),

                'travels'
                => $travels,

                'employees'
                => User::query()
                    ->where(
                        'role',
                        User::ROLE_USER
                    )
                    ->orderBy(
                        'nama_lengkap'
                    )
                    ->get(['id', 'nama_lengkap', 'nip', 'jabatan']),

                'destinations'
                => MasterTarif::query()
                    ->with('province:id,name')
                    ->orderBy(
                        'kota_tujuan'
                    )
                    ->get(['id', 'kota_tujuan', 'province_id']),

                'budgetAccounts'
                => BudgetAccount::query()
                    ->where('is_active', true)
                    ->orWhere('code', $travels->first()->akun_anggaran)
                    ->orderBy('code')
                    ->get(),

                'dailyAllowanceCategories'
                => DailyAllowanceRate::categoryLabels(),

                'templateLabel'
                => $travels->first()->sptTemplateLabel(),

                'usesMemo'
                => $travels->first()->usesMemoTemplate(),

                'usesDipa'
                => $travels->first()->usesDipaTemplate(),
            ]
        );
    }



    public function update(
        Request $request,
        string $sptGroupId
    ): RedirectResponse {
        $existingTravel = $this->groupTravels($sptGroupId)->first();

        $data = $this->validatedData($request, $existingTravel);

        /*
         * Hitung ulang karena Officer mungkin mengubah:
         *
         * - kota tujuan
         * - tanggal
         * - jenis angkutan
         */
        [$days, $estimate, $rates] =
            $this->calculateTrip(
                $data,
                $existingTravel
            );

        $dipaSnapshot = $this->resolveDipaSnapshot($data, $existingTravel);

        $commonData =
            $this->commonTravelData(
                $data,
                $days,
                $estimate,
                $rates,
                $dipaSnapshot,
                [
                    'spt_internal_reference' => $existingTravel->spt_internal_reference,
                    'spt_number_mode' => $existingTravel->spt_number_mode,
                    'spt_external_number' => $existingTravel->spt_external_number,
                    'spt_external_number_recorded_at' => $existingTravel->spt_external_number_recorded_at,
                    'spt_external_number_recorded_by' => $existingTravel->spt_external_number_recorded_by,
                ]
            );

        /*
         * Daftar pegawai yang dipilih setelah edit.
         */
        $requestedUserIds =
            collect(
                $data['user_ids']
            )
            ->map(
                fn($id): int =>
                (int) $id
            )
            ->unique()
            ->values();

        /*
         * Gunakan transaction supaya operasi:
         *
         * update pegawai lama
         * + hapus pegawai
         * + tambah pegawai
         *
         * dianggap sebagai satu kesatuan.
         */
        $invalidatedConceptPaths = [];

        DB::transaction(
            function () use (
                $sptGroupId,
                $requestedUserIds,
                $commonData,
                &$invalidatedConceptPaths,
            ): void {
                $workflow = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->first();

                /*
                 * lockForUpdate mencegah record ini berubah
                 * dari request lain ketika sedang kita edit.
                 */
                $travels =
                    PerjalananDinas::query()
                    ->where(
                        'spt_group_id',
                        $sptGroupId
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                abort_if(
                    $travels->isEmpty(),
                    404,
                    'Data Surat Tugas tidak ditemukan.'
                );

                /*
                 * WAJIB cek status lagi di backend.
                 *
                 * Bisa saja Officer membuka halaman edit
                 * ketika masih siap_jalan, lalu sebelum
                 * tombol Simpan ditekan pegawai sudah
                 * mengirim realisasi.
                 */
                if (! $this->canModify($travels)) {
                    throw ValidationException::withMessages([
                        'spt' =>
                        'Perubahan dibatalkan karena proses perjalanan salah satu pegawai sudah berjalan.',
                    ]);
                }

                if ($workflow) {
                    $preparedVersions = SptSrikandiVersion::query()
                        ->where('workflow_id', $workflow->id)
                        ->whereNull('submitted_at')
                        ->lockForUpdate()
                        ->get();
                    $invalidatedConceptPaths = $preparedVersions->pluck('docx_path')->filter()->all();
                    SptSrikandiVersion::query()
                        ->whereKey($preparedVersions->modelKeys())
                        ->delete();

                    if ($workflow->status === SptSrikandiWorkflow::STATUS_REVISION) {
                        $workflow->update(['status' => SptSrikandiWorkflow::STATUS_DRAFT]);
                    }
                }

                /*
                 * Pegawai sebelum diedit.
                 */
                $existingUserIds =
                    $travels
                    ->pluck('user_id')
                    ->map(
                        fn($id): int =>
                        (int) $id
                    )
                    ->unique()
                    ->values();

                /*
                 * =================================================
                 * A. PEGAWAI YANG DIHAPUS DARI SPT
                 * =================================================
                 *
                 * Lama:
                 * [34, 35]
                 *
                 * Baru:
                 * [34]
                 *
                 * Maka ID 35 harus dihapus.
                 */
                $userIdsToRemove =
                    $existingUserIds
                    ->diff(
                        $requestedUserIds
                    );

                if (
                    $userIdsToRemove->isNotEmpty()
                ) {
                    PerjalananDinas::query()
                        ->where(
                            'spt_group_id',
                            $sptGroupId
                        )
                        ->whereIn(
                            'user_id',
                            $userIdsToRemove->all()
                        )
                        ->delete();
                }

                /*
                 * =================================================
                 * B. PEGAWAI YANG TETAP ADA
                 * =================================================
                 *
                 * Update seluruh data umum SPT:
                 *
                 * nomor
                 * memo
                 * tujuan
                 * tanggal
                 * transportasi
                 * estimasi
                 * dan seterusnya.
                 */
                $existingRequestedUserIds =
                    $existingUserIds
                    ->intersect(
                        $requestedUserIds
                    );

                if (
                    $existingRequestedUserIds->isNotEmpty()
                ) {
                    PerjalananDinas::query()
                        ->where(
                            'spt_group_id',
                            $sptGroupId
                        )
                        ->whereIn(
                            'user_id',
                            $existingRequestedUserIds->all()
                        )
                        ->update(
                            $commonData
                        );
                }

                /*
                 * =================================================
                 * C. PEGAWAI BARU
                 * =================================================
                 *
                 * Lama:
                 * [34]
                 *
                 * Baru:
                 * [34, 40]
                 *
                 * Maka pegawai 40 dibuatkan transaksi baru
                 * tetapi tetap menggunakan spt_group_id
                 * yang sama.
                 */
                $newUserIds =
                    $requestedUserIds
                    ->diff(
                        $existingUserIds
                    );

                foreach (
                    $newUserIds
                    as $userId
                ) {
                    PerjalananDinas::query()
                        ->create([
                            'spt_group_id'
                            => $sptGroupId,

                            'user_id'
                            => (int) $userId,

                            'created_by'
                            => (int) ($travels->first()->created_by ?: auth()->id()),

                            ...$commonData,

                            'status'
                            => $travels->first()->status,
                        ]);
                }
            }
        );

        foreach ($invalidatedConceptPaths as $path) {
            $this->srikandiStorage->delete($path);
        }

        $referenceId = PerjalananDinas::query()
            ->where('spt_group_id', $sptGroupId)
            ->min('id');
        if ($referenceId) {
            $this->documentPrewarmer->afterResponse(
                TravelPdfDocumentService::TYPE_SPT,
                (int) $referenceId
            );
        }

        return redirect()
            ->route(
                'travel-orders.show',
                [
                    'sptGroupId'
                    => $sptGroupId,
                ] + OfficerSptNavigation::context($request)
            )
            ->with(
                'success',
                'Surat Tugas berhasil diperbarui.'
            );
    }


    /*
     * =====================================================
     * DELETE
     * =====================================================
     */

    public function destroy(
        string $sptGroupId
    ): RedirectResponse {
        $workflowFiles = [];

        DB::transaction(
            function () use (
                $sptGroupId,
                &$workflowFiles,
            ): void {
                $workflow = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->first();

                /*
                 * Ambil dan kunci seluruh anggota SPT.
                 */
                $travels =
                    PerjalananDinas::query()
                    ->where(
                        'spt_group_id',
                        $sptGroupId
                    )
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                abort_if(
                    $travels->isEmpty(),
                    404,
                    'Data Surat Tugas tidak ditemukan.'
                );

                /*
                 * SPT tidak boleh dihapus apabila salah
                 * satu pegawai sudah menjalankan proses.
                 */
                if (! $this->canDelete($travels)) {
                    throw ValidationException::withMessages([
                        'spt' =>
                        'Surat Tugas tidak dapat dihapus karena proses perjalanan salah satu pegawai sudah berjalan.',
                    ]);
                }

                if ($workflow) {
                    $workflowFiles = [
                        $workflow->draft_pdf_path,
                        $workflow->official_pdf_path,
                        ...$workflow->versions()->pluck('docx_path')->all(),
                    ];
                    $workflow->delete();
                }

                /*
                 * Hapus SEMUA transaksi anggota SPT.
                 *
                 * Bukan hanya satu ID.
                 */
                PerjalananDinas::query()
                    ->where(
                        'spt_group_id',
                        $sptGroupId
                    )
                    ->delete();
            }
        );

        foreach ($workflowFiles as $path) {
            $this->srikandiStorage->delete($path);
        }

        return redirect()
            ->route('dashboard.officer')
            ->with(
                'success',
                'Surat Tugas berhasil dihapus.'
            );
    }


    /*
     * =====================================================
     * HELPER: VALIDATION
     * =====================================================
     */

    private function validatedData(
        Request $request,
        ?PerjalananDinas $existingTravel = null
    ): array {
        $numberMode = trim((string) $request->input('spt_number_mode', ''));
        if ($numberMode === '') {
            $numberMode = $request->filled('no_spt')
                ? PerjalananDinas::NUMBER_MODE_MANUAL
                : PerjalananDinas::NUMBER_MODE_EXTERNAL;
        }
        if ($existingTravel) {
            $numberMode = $existingTravel->spt_number_mode ?: PerjalananDinas::NUMBER_MODE_MANUAL;
        }
        $request->merge(['spt_number_mode' => $numberMode]);

        $variantKey = $existingTravel?->spt_template_variant
            ?: trim((string) $request->input('spt_template_variant', ''));
        $variant = SptTemplateVariant::get($variantKey);
        $usesMemo = $existingTravel
            ? $existingTravel->usesMemoTemplate()
            : ($variant ? $variant['uses_memo'] : true);

        $data = $request->validate([
            'spt_number_mode' => [
                'required',
                Rule::in([
                    PerjalananDinas::NUMBER_MODE_EXTERNAL,
                    PerjalananDinas::NUMBER_MODE_MANUAL,
                ]),
            ],

            'no_spt' => [
                Rule::requiredIf($numberMode === PerjalananDinas::NUMBER_MODE_MANUAL),
                'nullable',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail) use ($numberMode): void {
                    if ($numberMode === PerjalananDinas::NUMBER_MODE_MANUAL
                        && trim((string) $value) === PerjalananDinas::NUMBER_PLACEHOLDER) {
                        $fail('Nomor manual tidak boleh menggunakan parameter nomor naskah.');
                    }
                },
            ],

            'menimbang' => [
                'required',
                'string',
                'max:255',
            ],

            'no_memo' => [
                Rule::requiredIf($usesMemo),
                'nullable',
                'string',
                'max:255',
            ],

            'perihal_memo' => [
                Rule::requiredIf($usesMemo),
                'nullable',
                'string',
                'max:255',
            ],

            'tgl_memo' => [
                Rule::requiredIf($usesMemo),
                'nullable',
                'date',
            ],

            'maksud_perjalanan' => [
                'required',
                'string',
            ],

            'user_ids' => [
                'required',
                'array',
                'min:1',
            ],

            /*
             * Selain memastikan user ada,
             * kita memastikan yang dipilih memang
             * role pegawai/user.
             */
            'user_ids.*' => [
                'required',
                'integer',
                'distinct',

                Rule::exists(
                    'users',
                    'id'
                )->where(
                    fn($query) =>
                    $query->where(
                        'role',
                        User::ROLE_USER
                    )
                ),
            ],

            'kota_tujuan' => [
                'required',
                'string',

                Rule::exists(
                    'master_tarif',
                    'kota_tujuan'
                ),
            ],

            'tempat_berangkat' => [
                'required',
                'string',
                'max:50',
            ],

            'tgl_berangkat' => [
                'required',
                'date',
            ],

            'tgl_kembali' => [
                'required',
                'date',
                'after_or_equal:tgl_berangkat',
            ],

            'angkutan' => [
                'required',

                Rule::in([
                    'Pesawat Udara',
                    'Transportasi Darat',
                ]),
            ],

            'daily_allowance_category' => [
                'nullable',
                Rule::in(array_keys(DailyAllowanceRate::categoryLabels())),
            ],

            'spt_template_id' => [
                'nullable',
                'integer',
                Rule::exists('spt_templates', 'id')->where(
                    fn($query) => $query->where('is_active', true)
                ),
            ],

            'spt_template_variant' => [
                'nullable',
                'string',
                Rule::in(array_keys(SptTemplateVariant::all())),
            ],

            'akun_anggaran' => [
                'required',
                'string',
                'max:50',
                Rule::exists('budget_accounts', 'code')->where(
                    fn($query) => $query->where('is_active', true)
                ),
            ],
        ]);

        if (! empty($data['spt_template_id']) && ! empty($data['spt_template_variant'])) {
            throw ValidationException::withMessages([
                'spt_template_variant' => 'Pilih salah satu jenis template SPT.',
            ]);
        }

        if ($existingTravel) {
            $data['spt_template_id'] = $existingTravel->spt_template_id;
            $data['spt_template_variant'] = $existingTravel->spt_template_variant;
            $data['spt_number_mode'] = $numberMode;
            $data['no_spt'] = $existingTravel->spt_number_mode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                ? $existingTravel->no_spt
                : trim((string) $data['no_spt']);
        } else {
            $data['spt_template_id'] = $data['spt_template_id'] ?? null;
            $data['spt_template_variant'] = $data['spt_template_variant'] ?? null;
            $data['no_spt'] = $numberMode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                ? PerjalananDinas::NUMBER_PLACEHOLDER
                : trim((string) $data['no_spt']);
        }

        if (! $usesMemo) {
            $data['no_memo'] = null;
            $data['perihal_memo'] = null;
            $data['tgl_memo'] = null;
        }

        return $data;
    }


    /*
     * =====================================================
     * HELPER: HITUNG LAMA & ESTIMASI
     * =====================================================
     */

    private function calculateTrip(
        array $data,
        ?PerjalananDinas $existingTravel = null
    ): array {
        $start =
            CarbonImmutable::parse(
                $data['tgl_berangkat']
            )->startOfDay();

        $end =
            CarbonImmutable::parse(
                $data['tgl_kembali']
            )->startOfDay();

        $days =
            (int) $start->diffInDays(
                $end
            )
            + 1;

        try {
            $rates = $this->calculator->ratesForOrder(
                $data['kota_tujuan'],
                $data['angkutan'],
                $data['tgl_berangkat'],
                $data['daily_allowance_category'] ?? null,
                $existingTravel,
                $days,
                $data['tempat_berangkat']
            );
        } catch (DomainException $exception) {
            $message = mb_strtolower($exception->getMessage());
            $errorField = str_contains($message, 'transport')
                ? 'angkutan'
                : (str_contains($message, 'hotel')
                    ? 'kota_tujuan'
                    : 'daily_allowance_category');
            throw ValidationException::withMessages([
                $errorField => $exception->getMessage(),
            ]);
        }

        if (
            $rates['daily_allowance_source'] === 'pmk'
            && ($rates['daily_allowance_category'] ?? null) === DailyAllowanceRate::CATEGORY_INSIDE_CITY_OVER_8_HOURS
            && $days !== 1
        ) {
            throw ValidationException::withMessages([
                'daily_allowance_category' => 'Kategori Dalam Kota Lebih dari 8 Jam hanya berlaku untuk perjalanan satu hari.',
            ]);
        }
        $estimate = ($rates['daily_allowance'] * $days)
            + ($rates['hotel_per_day'] * $rates['hotel_nights'])
            + $rates['transport_limit'];

        return [
            $days,
            $estimate,
            $rates,
        ];
    }

    /** @return array<int, array{label: string, rate: float, quantity: int, unit: string, total: float, source: string, tone: string}> */
    private function costPreviewComponents(array $rates, int $days): array
    {
        $components = [
            $this->costPreviewRow(
                'Uang harian',
                (float) $rates['daily_allowance'],
                $days,
                'hari',
                (string) $rates['daily_allowance_source']
            ),
        ];

        $nights = (int) $rates['hotel_nights'];
        if ($nights > 0) {
            $components[] = $this->costPreviewRow(
                'Penginapan',
                (float) $rates['hotel_per_day'],
                $nights,
                'malam',
                (string) $rates['hotel_rate_source']
            );
        }

        if (($rates['transport_rate_source'] ?? 'legacy') === 'pmk_air') {
            $components[] = $this->costPreviewRow(
                'Terminal asal',
                (float) $rates['terminal_origin_one_way'],
                2,
                'kali',
                (string) $rates['terminal_origin_source']
            );
            $components[] = $this->costPreviewRow(
                'Tiket pesawat ekonomi PP',
                (float) $rates['airfare_economy_pp'],
                1,
                'PP',
                (string) $rates['airfare_rate_source']
            );
            $components[] = $this->costPreviewRow(
                'Terminal tujuan',
                (float) $rates['terminal_destination_one_way'],
                2,
                'kali',
                (string) $rates['terminal_destination_source']
            );
        } elseif (($rates['transport_rate_source'] ?? 'legacy') === 'pmk_ground') {
            $components[] = $this->costPreviewRow(
                'Transportasi darat PP',
                (float) $rates['ground_transport_one_way'],
                2,
                'arah',
                'pmk'
            );
        } else {
            $components[] = $this->costPreviewRow(
                'Transportasi',
                (float) $rates['transport_limit'],
                1,
                'perjalanan',
                'legacy'
            );
        }

        return $components;
    }

    private function costPreviewRow(
        string $label,
        float $rate,
        int $quantity,
        string $unit,
        string $source
    ): array {
        return [
            'label' => $label,
            'rate' => $rate,
            'quantity' => $quantity,
            'unit' => $unit,
            'total' => $rate * $quantity,
            'source' => match ($source) {
                'pmk', 'pmk_air', 'pmk_ground' => 'PMK',
                'unavailable' => 'Tidak tersedia',
                default => 'Legacy',
            },
            'tone' => match ($source) {
                'pmk', 'pmk_air', 'pmk_ground' => 'success',
                'unavailable' => 'danger',
                default => 'warning',
            },
        ];
    }


    /*
     * =====================================================
     * HELPER: DATA UMUM SPT
     * =====================================================
     */

    private function commonTravelData(
        array $data,
        int $days,
        float $estimate,
        array $rates,
        ?array $dipaSnapshot = null,
        array $numbering = []
    ): array {
        return [
            'spt_template_id'
            => isset($data['spt_template_id']) && $data['spt_template_id'] !== ''
                ? (int) $data['spt_template_id']
                : null,

            'spt_template_variant'
            => $data['spt_template_variant'] ?: null,

            ...$numbering,

            'no_spt'
            => $data['no_spt'],

            'menimbang'
            => $data['menimbang'],

            'no_memo'
            => $data['no_memo'] ?? null,

            'perihal_memo'
            => $data['perihal_memo'] ?? null,

            'tgl_memo'
            => $data['tgl_memo'] ?? null,

            'dipa_setting_id'
            => $dipaSnapshot['dipa_setting_id'] ?? null,

            'dipa_fiscal_year_snapshot'
            => $dipaSnapshot['dipa_fiscal_year_snapshot'] ?? null,

            'dipa_number_snapshot'
            => $dipaSnapshot['dipa_number_snapshot'] ?? null,

            'dipa_date_snapshot'
            => $dipaSnapshot['dipa_date_snapshot'] ?? null,

            'maksud_perjalanan'
            => $data['maksud_perjalanan'],

            'kota_tujuan'
            => $data['kota_tujuan'],

            'tempat_berangkat'
            => $data['tempat_berangkat'],

            'tgl_berangkat'
            => $data['tgl_berangkat'],

            'tgl_kembali'
            => $data['tgl_kembali'],

            'lama_hari'
            => $days,

            'angkutan'
            => $data['angkutan'],

            'akun_anggaran'
            => $data['akun_anggaran'],

            'estimasi_biaya'
            => $estimate,

            'uang_harian_per_hari_snapshot'
            => $rates['daily_allowance'],

            'daily_allowance_source'
            => $rates['daily_allowance_source'],

            'daily_allowance_category'
            => $rates['daily_allowance_category'],

            'daily_allowance_regulation_id'
            => $rates['daily_allowance_regulation_id'],

            'daily_allowance_province_id'
            => $rates['daily_allowance_province_id'],

            'batas_hotel_per_hari_snapshot'
            => $rates['hotel_per_day'],

            'hotel_rate_source'
            => $rates['hotel_rate_source'],

            'hotel_regulation_id'
            => $rates['hotel_regulation_id'],

            'hotel_province_id'
            => $rates['hotel_province_id'],

            'hotel_rate_group'
            => $rates['hotel_rate_group'],

            'hotel_nights_snapshot'
            => $rates['hotel_nights'],

            'batas_transport_snapshot'
            => $rates['transport_limit'],

            'transport_rate_source'
            => $rates['transport_rate_source'],

            'ground_transport_regulation_id'
            => $rates['ground_transport_regulation_id'],

            'ground_transport_province_id'
            => $rates['ground_transport_province_id'],

            'ground_transport_origin'
            => $rates['ground_transport_origin'],

            'ground_transport_destination'
            => $rates['ground_transport_destination'],

            'ground_transport_one_way_snapshot'
            => $rates['ground_transport_one_way'],

            'air_transport_regulation_id'
            => $rates['air_transport_regulation_id'],

            'air_origin_province_id'
            => $rates['air_origin_province_id'],

            'air_destination_province_id'
            => $rates['air_destination_province_id'],

            'air_origin_city'
            => $rates['air_origin_city'],

            'air_destination_city'
            => $rates['air_destination_city'],

            'airfare_class'
            => $rates['airfare_class'],

            'terminal_origin_source'
            => $rates['terminal_origin_source'],

            'terminal_origin_one_way_snapshot'
            => $rates['terminal_origin_one_way'],

            'terminal_destination_source'
            => $rates['terminal_destination_source'],

            'terminal_destination_one_way_snapshot'
            => $rates['terminal_destination_one_way'],

            'airfare_rate_source'
            => $rates['airfare_rate_source'],

            'airfare_business_pp_snapshot'
            => $rates['airfare_business_pp'],

            'airfare_economy_pp_snapshot'
            => $rates['airfare_economy_pp'],
        ];
    }


    /*
     * =====================================================
     * HELPER: AMBIL SATU GRUP SPT
     * =====================================================
     */

    /** @return array{dipa_setting_id: ?int, dipa_fiscal_year_snapshot: int, dipa_number_snapshot: string, dipa_date_snapshot: string}|null */
    private function resolveDipaSnapshot(array $data, ?PerjalananDinas $existingTravel = null): ?array
    {
        $variant = SptTemplateVariant::get($data['spt_template_variant'] ?? null);

        if (! ($variant['uses_dipa'] ?? false)) {
            return null;
        }

        $fiscalYear = (int) CarbonImmutable::parse($data['tgl_berangkat'])->year;

        if ($existingTravel
            && (int) $existingTravel->dipa_fiscal_year_snapshot === $fiscalYear
            && $existingTravel->dipa_number_snapshot
            && $existingTravel->dipa_date_snapshot) {
            return [
                'dipa_setting_id' => $existingTravel->dipa_setting_id
                    ? (int) $existingTravel->dipa_setting_id
                    : null,
                'dipa_fiscal_year_snapshot' => $fiscalYear,
                'dipa_number_snapshot' => (string) $existingTravel->dipa_number_snapshot,
                'dipa_date_snapshot' => $existingTravel->dipa_date_snapshot->format('Y-m-d'),
            ];
        }

        $setting = DipaSetting::query()->where('fiscal_year', $fiscalYear)->first();
        if (! $setting) {
            throw ValidationException::withMessages([
                'tgl_berangkat' => "Konfigurasi DIPA TA {$fiscalYear} belum tersedia. Hubungi Program sebelum menyimpan SPT.",
            ]);
        }

        return [
            'dipa_setting_id' => (int) $setting->id,
            'dipa_fiscal_year_snapshot' => $fiscalYear,
            'dipa_number_snapshot' => $setting->document_number,
            'dipa_date_snapshot' => $setting->document_date->format('Y-m-d'),
        ];
    }

    private function generateInternalReference(int $year): string
    {
        do {
            $suffix = strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8));
            $reference = "REF-SPT/{$year}/{$suffix}";
        } while (PerjalananDinas::query()->where('spt_internal_reference', $reference)->exists());

        return $reference;
    }

    private function groupTravels(
        string $sptGroupId
    ): EloquentCollection {
        $travels =
            PerjalananDinas::query()
            ->with(['pegawai', 'sptTemplate'])
            ->where(
                'spt_group_id',
                $sptGroupId
            )
            ->orderBy('id')
            ->get();

        abort_if(
            $travels->isEmpty(),
            404,
            'Data Surat Tugas tidak ditemukan.'
        );

        return $travels;
    }


    /*
     * =====================================================
     * HELPER: BOLEH EDIT / DELETE?
     * =====================================================
     */

    private function canModify(
        EloquentCollection $travels
    ): bool {
        /*
         * SEMUA anggota harus masih siap_jalan.
         *
         * Satu saja sudah pending/approved/rejected,
         * seluruh SPT kita kunci.
         */
        $groupId = $travels->first()?->spt_group_id;
        if ($groupId) {
            $workflow = SptSrikandiWorkflow::query()
                ->where('spt_group_id', $groupId)
                ->first();

            if ($workflow && ! in_array($workflow->status, [
                SptSrikandiWorkflow::STATUS_DRAFT,
                SptSrikandiWorkflow::STATUS_REVISION,
            ], true)) {
                return false;
            }
        }

        return $travels->every(fn (PerjalananDinas $travel): bool => in_array(
            $travel->status,
            [PerjalananDinas::STATUS_DRAFT, PerjalananDinas::STATUS_READY],
            true
        ));
    }

    private function canDelete(EloquentCollection $travels): bool
    {
        $groupId = $travels->first()?->spt_group_id;
        if ($groupId) {
            $workflow = SptSrikandiWorkflow::query()
                ->where('spt_group_id', $groupId)
                ->first();

            if ($workflow) {
                if ($workflow->status !== SptSrikandiWorkflow::STATUS_DRAFT) {
                    return false;
                }

                if ($workflow->versions()->whereNotNull('submitted_at')->exists()) {
                    return false;
                }
            }
        }

        return $travels->every(fn(PerjalananDinas $travel): bool => in_array(
            $travel->status,
            [PerjalananDinas::STATUS_DRAFT, PerjalananDinas::STATUS_READY],
            true
        ));
    }
}
