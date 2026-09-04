<?php

namespace App\Http\Controllers;

use App\Models\MasterTarif;
use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRate;
use App\Models\PerjalananDinas;
use App\Models\SptTemplate;
use App\Models\User;
use App\Services\TravelCostCalculator;
use App\Services\Documents\DocumentCachePrewarmer;
use App\Services\Documents\TravelPdfDocumentService;
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
        private readonly DocumentCachePrewarmer $documentPrewarmer
    ) {}
    public function create(Request $request): View
    {
        $selectedTemplateId = $this->resolveSelectedTemplateId($request);

        if ($selectedTemplateId === null) {
            return view('travel.template-select', [
                'templates' => SptTemplate::query()
                    ->withCount('perjalananDinas')
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('nama')
                    ->get(),
            ]);
        }

        return view('travel.create', $this->createFormData($selectedTemplateId));
    }

    private function resolveSelectedTemplateId(Request $request): ?int
    {
        $raw = trim((string) $request->query('template', ''));

        if ($raw === '') {
            return null;
        }

        if ($raw === 'system') {
            return 0;
        }

        if (! ctype_digit($raw)) {
            return null;
        }

        $exists = SptTemplate::query()
            ->whereKey((int) $raw)
            ->where('is_active', true)
            ->exists();

        return $exists ? (int) $raw : null;
    }

    /** @return array<string, mixed> */
    private function createFormData(?int $selectedTemplateId): array
    {
        return [
            'selectedTemplateId' => $selectedTemplateId,
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
            'sptTemplates' => $this->selectableTemplates(),
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

        $commonData =
            $this->commonTravelData(
                $data,
                $days,
                $estimate,
                $rates
            );

        DB::transaction(
            function () use (
                $data,
                $sptGroupId,
                $commonData,
                $request
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
                            => PerjalananDinas::STATUS_READY,
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

        return redirect()
            ->route('dashboard.officer')
            ->with(
                'success',
                'Semua SPT berhasil disimpan.'
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

        return view(
            'travel.show',
            [
                'travel' => $travel,
                'travels' => $travels,
                'canModify' => $canModify,
            ]
        );
    }


    public function edit(
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
                    ]
                )
                ->withErrors([
                    'spt' =>
                    'Surat Tugas tidak dapat diedit karena proses perjalanan salah satu pegawai sudah berjalan.',
                ]);
        }

        return view(
            'travel.edit',
            [
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

                'sptTemplates'
                => $this->selectableTemplates(),
            ]
        );
    }



    public function update(
        Request $request,
        string $sptGroupId
    ): RedirectResponse {
        $existingTravel = $this->groupTravels($sptGroupId)->first();

        $data =
            $this->validatedData(
                $request
            );

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

        $commonData =
            $this->commonTravelData(
                $data,
                $days,
                $estimate,
                $rates
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
        DB::transaction(
            function () use (
                $sptGroupId,
                $requestedUserIds,
                $commonData
            ): void {
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
                            => PerjalananDinas::STATUS_READY,
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

        return redirect()
            ->route(
                'travel-orders.show',
                [
                    'sptGroupId'
                    => $sptGroupId,
                ]
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
        DB::transaction(
            function () use (
                $sptGroupId
            ): void {
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
                if (! $this->canModify($travels)) {
                    throw ValidationException::withMessages([
                        'spt' =>
                        'Surat Tugas tidak dapat dihapus karena proses perjalanan salah satu pegawai sudah berjalan.',
                    ]);
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
        Request $request
    ): array {
        return $request->validate([
            'no_spt' => [
                'required',
                'string',
                'max:50',
            ],

            'menimbang' => [
                'required',
                'string',
                'max:255',
            ],

            'no_memo' => [
                'required',
                'string',
                'max:255',
            ],

            'perihal_memo' => [
                'required',
                'string',
                'max:255',
            ],

            'tgl_memo' => [
                'required',
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

            'akun_anggaran' => [
                'required',
                'string',
                'max:50',
                Rule::exists('budget_accounts', 'code')->where(
                    fn($query) => $query->where('is_active', true)
                ),
            ],
        ]);
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
        array $rates
    ): array {
        return [
            'spt_template_id'
            => isset($data['spt_template_id']) && $data['spt_template_id'] !== ''
                ? (int) $data['spt_template_id']
                : null,

            'no_spt'
            => $data['no_spt'],

            'menimbang'
            => $data['menimbang'],

            'no_memo'
            => $data['no_memo'],

            'perihal_memo'
            => $data['perihal_memo'],

            'tgl_memo'
            => $data['tgl_memo'],

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

    private function selectableTemplates(): EloquentCollection
    {
        return SptTemplate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('nama')
            ->get();
    }

    private function groupTravels(
        string $sptGroupId
    ): EloquentCollection {
        $travels =
            PerjalananDinas::query()
            ->with('pegawai')
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
        return $travels->every(
            fn(
                PerjalananDinas $travel
            ): bool =>
            $travel->status
                === PerjalananDinas::STATUS_READY
        );
    }
}
