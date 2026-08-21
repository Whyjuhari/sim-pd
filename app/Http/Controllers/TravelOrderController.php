<?php

namespace App\Http\Controllers;

use App\Models\MasterTarif;
use App\Models\BudgetAccount;
use App\Models\PerjalananDinas;
use App\Models\User;
use App\Services\TravelCostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TravelOrderController extends Controller
{
    public function __construct(
        private readonly TravelCostCalculator $calculator
    ) {}

    /*
     * =====================================================
     * CREATE
     * =====================================================
     */

    public function create(): View
    {
        return view('travel.create', [
            'employees' => User::query()
                ->where('role', User::ROLE_USER)
                ->orderBy('nama_lengkap')
                ->get(),

            'destinations' => MasterTarif::query()
                ->orderBy('kota_tujuan')
                ->get(),

            'budgetAccounts' => BudgetAccount::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function store(
        Request $request
    ): RedirectResponse {
        /*
         * Validasi input menggunakan aturan yang sama
         * dengan proses UPDATE.
         */
        $data = $this->validatedData($request);

        /*
         * Lama perjalanan dan estimasi tidak dipercaya
         * dari browser. Kita hitung kembali di server.
         */
        [$days, $estimate, $rates] =
            $this->calculateTrip($data);

        /*
         * Satu UUID untuk seluruh pegawai
         * dalam Surat Tugas ini.
         */
        $sptGroupId =
            (string) Str::uuid();

        /*
         * Data yang sama untuk seluruh anggota SPT.
         */
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

        return redirect()
            ->route('dashboard.officer')
            ->with(
                'success',
                'Semua SPT berhasil disimpan.'
            );
    }


    /*
     * =====================================================
     * READ / DETAIL
     * =====================================================
     */

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

        /*
         * Jangan hanya menyembunyikan tombol.
         * URL /edit juga harus dilindungi backend.
         */
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
                    ->get(),

                'destinations'
                => MasterTarif::query()
                    ->orderBy(
                        'kota_tujuan'
                    )
                    ->get(),

                'budgetAccounts'
                => BudgetAccount::query()
                    ->where('is_active', true)
                    ->orWhere('code', $travels->first()->akun_anggaran)
                    ->orderBy('code')
                    ->get(),
            ]
        );
    }


    /*
     * =====================================================
     * UPDATE
     * =====================================================
     */

    public function update(
        Request $request,
        string $sptGroupId
    ): RedirectResponse {
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
                $data
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

            'akun_anggaran' => [
                'required',
                'string',
                'max:50',
                Rule::exists('budget_accounts', 'code')->where(
                    fn ($query) => $query->where('is_active', true)
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
        array $data
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

        $rates = $this->calculator->rates(
            $data['kota_tujuan'],
            $data['angkutan']
        );
        $estimate = ($rates['daily_allowance'] * $days)
            + ($rates['hotel_per_day'] * $days)
            + $rates['transport_limit'];

        return [
            $days,
            $estimate,
            $rates,
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

            'batas_hotel_per_hari_snapshot'
            => $rates['hotel_per_day'],

            'batas_transport_snapshot'
            => $rates['transport_limit'],
        ];
    }


    /*
     * =====================================================
     * HELPER: AMBIL SATU GRUP SPT
     * =====================================================
     */

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
