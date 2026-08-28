<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\PerjalananDinasStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VerificationHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'decision' => ['nullable', Rule::in([
                PerjalananDinas::STATUS_APPROVED,
                PerjalananDinas::STATUS_REJECTED,
            ])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $decisions = [PerjalananDinas::STATUS_APPROVED, PerjalananDinas::STATUS_REJECTED];

        $historyQuery = PerjalananDinasStatusHistory::query()
            ->with(['perjalananDinas.pegawai', 'actor'])
            ->whereIn('to_status', $decisions)
            ->when($filters['decision'] ?? null, fn ($query, $status) => $query->where('to_status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->whereHas(
                'perjalananDinas',
                fn ($query) => $this->applyTravelSearch($query, $search)
            ));

        $legacyQuery = PerjalananDinas::query()
            ->with(['pegawai', 'verifier'])
            ->whereIn('status', $decisions)
            ->whereDoesntHave(
                'statusHistories',
                fn ($query) => $query->whereIn('to_status', $decisions)
            )
            ->when($filters['decision'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('verified_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('verified_at', '<=', $to));
        if ($search !== '') {
            $this->applyTravelSearch($legacyQuery, $search);
        }

        return view('verifications.history', [
            'filters' => [
                ...$filters,
                'q' => $search,
            ],
            'histories' => $historyQuery->latest('created_at')
                ->paginate(15, ['*'], 'decision_page')->withQueryString(),
            'legacyTravels' => $legacyQuery->orderByDesc('verified_at')->orderByDesc('id')
                ->paginate(15, ['*'], 'legacy_page')->withQueryString(),
        ]);
    }

    private function applyTravelSearch($query, string $search): mixed
    {
        return $query->where(function ($query) use ($search): void {
            $query->where('no_spt', 'like', "%{$search}%")
                ->orWhere('kota_tujuan', 'like', "%{$search}%")
                ->orWhereHas('pegawai', fn ($query) => $query
                    ->where('nama_lengkap', 'like', "%{$search}%"));
        });
    }
}
