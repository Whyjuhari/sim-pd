<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $route = match ($request->user()->role) {
            User::ROLE_ADMIN => 'dashboard.admin',
            User::ROLE_OFFICER => 'dashboard.officer',
            User::ROLE_PROGRAM => 'dashboard.program',
            User::ROLE_USER => 'dashboard.user',
            User::ROLE_VERIFIER => 'dashboard.verifier',
            User::ROLE_HEAD => 'dashboard.head',
            default => 'login',
        };

        return redirect()->route($route);
    }

    public function admin(): View
    {
        $topEmployees = User::query()
            ->whereHas('perjalananDinas', fn ($query) => $query->where('status', PerjalananDinas::STATUS_APPROVED))
            ->withCount(['perjalananDinas as jumlah_trip' => fn ($query) => $query->where('status', PerjalananDinas::STATUS_APPROVED)])
            ->orderByDesc('jumlah_trip')
            ->limit(5)
            ->get();

        return view('dashboards.admin', [
            'topEmployees' => $topEmployees,
            'stats' => [
                'users' => User::query()->where('role', User::ROLE_USER)->count(),
                'ready' => PerjalananDinas::query()->where('status', PerjalananDinas::STATUS_READY)->count(),
                'pending' => PerjalananDinas::query()->where('status', PerjalananDinas::STATUS_PENDING)->count(),
                'approved' => PerjalananDinas::query()->where('status', PerjalananDinas::STATUS_APPROVED)->count(),
            ],
            'travels' => PerjalananDinas::query()->with('pegawai')->latest('id')->paginate(10)->withQueryString(),
        ]);
    }

    public function officer(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                PerjalananDinas::STATUS_DRAFT,
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_PENDING,
                PerjalananDinas::STATUS_APPROVED,
                PerjalananDinas::STATUS_REJECTED,
            ])],
            'destination' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $status = (string) ($validated['status'] ?? '');
        $destination = trim((string) ($validated['destination'] ?? ''));

        $groupPages = PerjalananDinas::query()
            ->select('transaksi_perjadin.spt_group_id')
            ->join('users', 'users.id', '=', 'transaksi_perjadin.user_id')
            ->whereNotNull('transaksi_perjadin.spt_group_id')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    foreach ([
                        'no_spt', 'menimbang', 'no_memo', 'perihal_memo',
                        'maksud_perjalanan', 'kota_tujuan', 'tempat_berangkat', 'akun_anggaran',
                    ] as $field) {
                        $query->orWhere('transaksi_perjadin.'.$field, 'like', "%{$search}%");
                    }
                    $query->orWhere('users.nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('users.nip', 'like', "%{$search}%");
                });
            })
            ->when($status !== '', fn ($query) => $query->where('transaksi_perjadin.status', $status))
            ->when($destination !== '', fn ($query) => $query->where('transaksi_perjadin.kota_tujuan', $destination))
            ->groupBy('transaksi_perjadin.spt_group_id')
            ->orderByRaw('MAX(transaksi_perjadin.id) DESC')
            ->paginate(15)
            ->withQueryString();

        $groupIds = $groupPages->getCollection()->pluck('spt_group_id')->all();
        $travelsByGroup = PerjalananDinas::query()
            ->with('pegawai')
            ->whereIn('spt_group_id', $groupIds)
            ->orderBy('id')
            ->get()
            ->groupBy('spt_group_id');

        $groupPages->setCollection(collect($groupIds)->map(function (string $groupId) use ($travelsByGroup): array {
            $travels = $travelsByGroup->get($groupId, collect());

            return [
                'travel' => $travels->first(),
                'travels' => $travels->values(),
                'employees' => $travels->pluck('pegawai')->filter()->values(),
            ];
        }));

        return view('dashboards.officer', [
            'sptGroups' => $groupPages,
            'destinationOptions' => PerjalananDinas::query()
                ->whereNotNull('kota_tujuan')
                ->distinct()->orderBy('kota_tujuan')->pluck('kota_tujuan'),
            'filters' => ['q' => $search, 'status' => $status, 'destination' => $destination],
            'statusOptions' => [
                PerjalananDinas::STATUS_READY => 'Siap Berjalan',
                PerjalananDinas::STATUS_PENDING => 'Menunggu Verifikasi',
                PerjalananDinas::STATUS_APPROVED => 'Selesai',
                PerjalananDinas::STATUS_REJECTED => 'Perlu Revisi',
                PerjalananDinas::STATUS_DRAFT => 'Draft SPT (Legacy)',
            ],
            'totalSpt' => PerjalananDinas::query()
                ->whereNotNull('spt_group_id')
                ->distinct()
                ->count('spt_group_id'),
        ]);
    }

    public function program(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in([
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_PENDING,
                PerjalananDinas::STATUS_APPROVED,
                PerjalananDinas::STATUS_REJECTED,
            ])],
            'destination' => ['nullable', 'string', 'max:50'],
        ]);
        $query = PerjalananDinas::query()
            ->with('pegawai')
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('tgl_berangkat', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('tgl_berangkat', '<=', $to))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['destination'] ?? null, fn ($query, $destination) => $query->where('kota_tujuan', $destination));

        return view('dashboards.program', [
            'travels' => (clone $query)->latest('id')->paginate(15)->withQueryString(),
            'stats' => [
                'count' => (clone $query)->count(),
                'estimate' => (float) (clone $query)->sum('estimasi_biaya'),
                'realized' => (float) (clone $query)->where('status', PerjalananDinas::STATUS_APPROVED)->sum('total_cair'),
            ],
            'filters' => $filters,
            'destinations' => PerjalananDinas::query()->distinct()->orderBy('kota_tujuan')->pluck('kota_tujuan'),
        ]);
    }

    public function user(Request $request): View
    {
        $base = PerjalananDinas::query()->where('user_id', $request->user()->id);

        return view('dashboards.user', [
            'travels' => (clone $base)->with('laporan')->latest('id')->paginate(10),
            'newTaskCount' => (clone $base)
                ->whereIn('status', [PerjalananDinas::STATUS_READY, PerjalananDinas::STATUS_REJECTED])
                ->count(),
        ]);
    }

    public function verifier(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $filter = fn ($query) => $query->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
            $query->where('no_spt', 'like', "%{$search}%")
                ->orWhere('kota_tujuan', 'like', "%{$search}%")
                ->orWhereHas('pegawai', fn ($query) => $query->where('nama_lengkap', 'like', "%{$search}%"));
        }));

        return view('dashboards.verifier', [
            'search' => $search,
            'pendingTravels' => $filter(
                PerjalananDinas::query()->with('pegawai')->where('status', PerjalananDinas::STATUS_PENDING)
            )->latest('id')->paginate(10, ['*'], 'pending_page')->withQueryString(),
            'approvedTravels' => $filter(
                PerjalananDinas::query()->with('pegawai')->where('status', PerjalananDinas::STATUS_APPROVED)
            )->latest('id')->paginate(10, ['*'], 'history_page')->withQueryString(),
        ]);
    }

    public function head(Request $request): View
    {
        $year = min(2100, max(2000, (int) $request->query('year', now()->year)));
        $query = PerjalananDinas::query()->whereYear('tgl_berangkat', $year);

        return view('dashboards.head', [
            'year' => $year,
            'stats' => [
                'total' => (clone $query)->count(),
                'ready' => (clone $query)->where('status', PerjalananDinas::STATUS_READY)->count(),
                'pending' => (clone $query)->where('status', PerjalananDinas::STATUS_PENDING)->count(),
                'approved' => (clone $query)->where('status', PerjalananDinas::STATUS_APPROVED)->count(),
                'estimate' => (float) (clone $query)->sum('estimasi_biaya'),
                'realized' => (float) (clone $query)->where('status', PerjalananDinas::STATUS_APPROVED)->sum('total_cair'),
            ],
            'destinations' => (clone $query)
                ->select('kota_tujuan', DB::raw('COUNT(*) as total'))
                ->groupBy('kota_tujuan')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),
            'travels' => (clone $query)->with('pegawai')->latest('id')->paginate(10)->withQueryString(),
        ]);
    }
}
