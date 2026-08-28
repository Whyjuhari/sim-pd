<?php

namespace App\Services;

use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use Illuminate\Support\Collection;

class RealizationDetailService
{
    /** @return array<int, array{key: string, category: string, code: string, description: string, order: int}> */
    public function presets(PerjalananDinas $travel): array
    {
        $items = [];

        if ((int) $travel->lama_hari > 1) {
            $items[] = $this->preset(
                'hotel',
                RealisasiRincian::CATEGORY_HOTEL,
                RealisasiRincian::CODE_HOTEL,
                'Penginapan selama perjalanan dinas',
                10
            );
        }

        if ($travel->angkutan === 'Pesawat Udara') {
            $origin = trim((string) $travel->tempat_berangkat) ?: 'Tempat asal';
            $destination = trim((string) $travel->kota_tujuan) ?: 'lokasi kegiatan';

            $items[] = $this->preset('local_departure', RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_LOCAL_DEPARTURE, "Transport lokal {$origin} → bandara keberangkatan", 20);
            $items[] = $this->preset('flight_ticket', RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_FLIGHT_TICKET, "Tiket pesawat {$origin} ↔ {$destination} (PP)", 30);
            $items[] = $this->preset('destination_outbound', RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_DESTINATION_OUTBOUND, "Bandara tujuan → lokasi kegiatan di {$destination}", 40);
            $items[] = $this->preset('destination_return', RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_DESTINATION_RETURN, "Lokasi kegiatan di {$destination} → bandara tujuan", 50);
            $items[] = $this->preset('local_return', RealisasiRincian::CATEGORY_TRANSPORT, RealisasiRincian::CODE_LOCAL_RETURN, "Bandara keberangkatan → {$origin}", 60);
        } elseif ($travel->transport_rate_source === 'pmk_ground') {
            $origin = trim((string) ($travel->ground_transport_origin ?: $travel->tempat_berangkat))
                ?: 'Tempat berangkat';
            $destination = trim((string) ($travel->ground_transport_destination ?: $travel->kota_tujuan))
                ?: 'Tujuan';

            $items[] = $this->preset(
                'ground_outbound',
                RealisasiRincian::CATEGORY_TRANSPORT,
                RealisasiRincian::CODE_GROUND_OUTBOUND,
                "Transportasi darat {$origin} → {$destination}",
                20
            );
            $items[] = $this->preset(
                'ground_return',
                RealisasiRincian::CATEGORY_TRANSPORT,
                RealisasiRincian::CODE_GROUND_RETURN,
                "Transportasi darat {$destination} → {$origin}",
                30
            );
        } else {
            $items[] = $this->preset(
                'ground_transport',
                RealisasiRincian::CATEGORY_TRANSPORT,
                RealisasiRincian::CODE_GROUND_TRANSPORT,
                'Transportasi darat / BBM',
                20
            );
        }

        return $items;
    }

    public function syncAggregates(PerjalananDinas $travel): void
    {
        $details = RealisasiRincian::query()
            ->where('perjalanan_dinas_id', $travel->id)
            ->get();

        $travel->forceFill([
            'biaya_hotel_real' => $this->sum($details, RealisasiRincian::CATEGORY_HOTEL, 'nilai_diajukan'),
            'biaya_tiket_real' => $this->sum($details, RealisasiRincian::CATEGORY_TRANSPORT, 'nilai_diajukan'),
            'biaya_hotel_approved' => $this->sum($details, RealisasiRincian::CATEGORY_HOTEL, 'nilai_disetujui'),
            'biaya_tiket_approved' => $this->sum($details, RealisasiRincian::CATEGORY_TRANSPORT, 'nilai_disetujui'),
        ])->save();
    }

    /** @return array<int, float> */
    public function recommendedApprovals(Collection $details, array $recommendation): array
    {
        $limits = [
            RealisasiRincian::CATEGORY_HOTEL => (float) $recommendation['hotel_limit'],
            RealisasiRincian::CATEGORY_TRANSPORT => (float) $recommendation['transport_limit'],
        ];
        $values = $details->mapWithKeys(fn (RealisasiRincian $detail): array => [
            (int) $detail->id => 0.0,
        ])->all();

        $groupedDetails = $details->groupBy('kategori');
        if (($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground') {
            foreach ($groupedDetails->get(RealisasiRincian::CATEGORY_TRANSPORT, collect()) as $detail) {
                if (in_array($detail->kode, [
                    RealisasiRincian::CODE_GROUND_OUTBOUND,
                    RealisasiRincian::CODE_GROUND_RETURN,
                ], true)) {
                    $values[(int) $detail->id] = max(0, (float) $detail->nilai_diajukan);
                }
            }
            $groupedDetails->forget(RealisasiRincian::CATEGORY_TRANSPORT);
        } elseif (($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_air') {
            foreach ($groupedDetails->get(RealisasiRincian::CATEGORY_TRANSPORT, collect()) as $detail) {
                $values[(int) $detail->id] = max(0, (float) $detail->nilai_diajukan);
            }
            $groupedDetails->forget(RealisasiRincian::CATEGORY_TRANSPORT);
        }

        foreach ($groupedDetails as $category => $categoryDetails) {
            $ordered = $categoryDetails->sortBy('urutan')->values();
            $claims = $ordered->mapWithKeys(fn (RealisasiRincian $detail): array => [
                (int) $detail->id => max(0, (int) round((float) $detail->nilai_diajukan * 100)),
            ]);
            $totalClaim = (int) $claims->sum();
            $limit = max(0, (int) round((float) ($limits[$category] ?? 0) * 100));
            $approvedTotal = min($totalClaim, $limit);

            if ($totalClaim === 0 || $approvedTotal === 0) {
                continue;
            }

            if ($totalClaim <= $limit) {
                foreach ($claims as $id => $claim) {
                    $values[(int) $id] = $claim / 100;
                }
                continue;
            }

            $allocated = 0;
            $fractions = [];
            foreach ($ordered as $index => $detail) {
                $id = (int) $detail->id;
                $claim = (int) $claims[$id];
                $exactShare = ($claim / $totalClaim) * $approvedTotal;
                $approved = min($claim, (int) floor($exactShare));
                $values[$id] = $approved / 100;
                $allocated += $approved;
                $fractions[] = [
                    'id' => $id,
                    'fraction' => $exactShare - $approved,
                    'order' => $index,
                    'capacity' => $claim - $approved,
                ];
            }

            usort($fractions, fn (array $left, array $right): int =>
                ($right['fraction'] <=> $left['fraction']) ?: ($left['order'] <=> $right['order'])
            );
            $remainder = $approvedTotal - $allocated;
            foreach ($fractions as $fraction) {
                if ($remainder <= 0) {
                    break;
                }
                if ($fraction['capacity'] <= 0) {
                    continue;
                }
                $values[$fraction['id']] += 0.01;
                $remainder--;
            }
        }

        return $values;
    }

    /** @return array{source: ?string, amount: ?float, terminal: bool} */
    public function benchmarkFor(PerjalananDinas $travel, ?string $code): array
    {
        if ($travel->transport_rate_source !== 'pmk_air') {
            return ['source' => null, 'amount' => null, 'terminal' => false];
        }

        return match ($code) {
            RealisasiRincian::CODE_LOCAL_DEPARTURE,
            RealisasiRincian::CODE_LOCAL_RETURN => [
                'source' => $travel->terminal_origin_source,
                'amount' => (float) ($travel->terminal_origin_one_way_snapshot ?? 0),
                'terminal' => true,
            ],
            RealisasiRincian::CODE_DESTINATION_OUTBOUND,
            RealisasiRincian::CODE_DESTINATION_RETURN => [
                'source' => $travel->terminal_destination_source,
                'amount' => (float) ($travel->terminal_destination_one_way_snapshot ?? 0),
                'terminal' => true,
            ],
            RealisasiRincian::CODE_FLIGHT_TICKET => [
                'source' => $travel->airfare_rate_source,
                'amount' => (float) ($travel->airfare_economy_pp_snapshot ?? 0),
                'terminal' => false,
            ],
            default => ['source' => 'no_benchmark', 'amount' => null, 'terminal' => false],
        };
    }

    private function preset(string $key, string $category, string $code, string $description, int $order): array
    {
        return compact('key', 'category', 'code', 'description', 'order');
    }

    private function sum(Collection $details, string $category, string $column): float
    {
        return (float) $details
            ->where('kategori', $category)
            ->sum(fn (RealisasiRincian $detail): float => (float) ($detail->{$column} ?? 0));
    }
}
