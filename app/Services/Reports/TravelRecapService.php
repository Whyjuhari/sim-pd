<?php

namespace App\Services\Reports;

use App\Models\PerjalananDinas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TravelRecapService
{
    public function programFilterRules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in([
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_PENDING,
                PerjalananDinas::STATUS_APPROVED,
                PerjalananDinas::STATUS_REJECTED,
            ])],
            'destination' => ['nullable', 'string', 'max:100'],
            'account' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function normalizeProgramFilters(array $filters): array
    {
        return [
            ...$filters,
            'destination' => trim((string) ($filters['destination'] ?? '')),
            'account' => trim((string) ($filters['account'] ?? '')),
        ];
    }

    public function programQuery(array $filters): Builder
    {
        return PerjalananDinas::query()
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('tgl_berangkat', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('tgl_berangkat', '<=', $to))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['destination'] ?? null, fn ($query, $destination) => $query->where('kota_tujuan', $destination))
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('akun_anggaran', $account));
    }

    public function headQuery(int $year): Builder
    {
        return PerjalananDinas::query()->whereYear('tgl_berangkat', $year);
    }

    public function summary(Builder $query): array
    {
        $estimate = (float) (clone $query)->sum('estimasi_biaya');
        $realized = (float) (clone $query)
            ->where('status', PerjalananDinas::STATUS_APPROVED)
            ->sum('total_cair');

        return [
            'count' => (clone $query)->count(),
            'estimate' => $estimate,
            'realized' => $realized,
            'difference' => $estimate - $realized,
        ];
    }

    public function grouped(Builder $query, ?int $fillYear = null): array
    {
        $months = $this->monthly($query);

        if ($fillYear !== null) {
            $indexed = $months->keyBy('key');
            $months = collect(range(1, 12))->map(function (int $month) use ($fillYear, $indexed): array {
                $key = sprintf('%04d-%02d', $fillYear, $month);

                return $indexed->get($key, $this->row($key, $this->monthLabel($key), 0, 0, 0));
            });
        }

        return [
            'months' => $months,
            'destinations' => $this->byField($query, 'kota_tujuan', 'Tanpa tujuan'),
            'accounts' => $this->byField($query, 'akun_anggaran', 'Tanpa MAK'),
        ];
    }

    private function monthly(Builder $query): Collection
    {
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', tgl_berangkat)"
            : "DATE_FORMAT(tgl_berangkat, '%Y-%m')";

        return (clone $query)
            ->selectRaw("{$expression} as group_key")
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('COALESCE(SUM(estimasi_biaya), 0) as estimasi')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = ? THEN total_cair ELSE 0 END), 0) as realisasi",
                [PerjalananDinas::STATUS_APPROVED]
            )
            ->groupByRaw($expression)
            ->orderByRaw($expression)
            ->get()
            ->map(fn ($item): array => $this->row(
                (string) $item->group_key,
                $this->monthLabel((string) $item->group_key),
                (int) $item->jumlah,
                (float) $item->estimasi,
                (float) $item->realisasi,
            ));
    }

    private function byField(Builder $query, string $field, string $emptyLabel): Collection
    {
        return (clone $query)
            ->selectRaw("{$field} as group_key")
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('COALESCE(SUM(estimasi_biaya), 0) as estimasi')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = ? THEN total_cair ELSE 0 END), 0) as realisasi",
                [PerjalananDinas::STATUS_APPROVED]
            )
            ->groupBy($field)
            ->orderByDesc('jumlah')
            ->get()
            ->map(function ($item) use ($emptyLabel): array {
                $key = trim((string) $item->group_key);

                return $this->row(
                    $key,
                    $key !== '' ? $key : $emptyLabel,
                    (int) $item->jumlah,
                    (float) $item->estimasi,
                    (float) $item->realisasi,
                );
            });
    }

    private function row(string $key, string $label, int $count, float $estimate, float $realized): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'estimate' => $estimate,
            'realized' => $realized,
            'difference' => $estimate - $realized,
        ];
    }

    private function monthLabel(string $key): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        [$year, $month] = array_pad(array_map('intval', explode('-', $key)), 2, 0);

        return isset($months[$month]) ? $months[$month].' '.$year : $key;
    }
}
