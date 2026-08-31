<?php

namespace App\Services\Reports;

use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use Illuminate\Database\Eloquent\Builder;

class PmkComplianceService
{
    public function summarize(Builder $query): array
    {
        $summary = $this->emptySummary();

        foreach ((clone $query)->with('rincianRealisasi')->lazyById(200) as $travel) {
            $travelSummary = $this->forTravel($travel);
            foreach (['items', 'within', 'over', 'legacy', 'unavailable'] as $key) {
                $summary[$key] += $travelSummary[$key];
            }
            $summary['over_amount'] += $travelSummary['over_amount'];
            if ($travelSummary['over'] + $travelSummary['legacy'] + $travelSummary['unavailable'] > 0) {
                $summary['travels_with_exceptions']++;
            }
        }

        $pmkItems = $summary['within'] + $summary['over'];
        $summary['compliance_rate'] = $pmkItems > 0
            ? (int) round(($summary['within'] / $pmkItems) * 100)
            : 0;

        return $summary;
    }

    public function forTravel(PerjalananDinas $travel): array
    {
        $travel->loadMissing('rincianRealisasi');
        $summary = $this->emptySummary();

        foreach ($travel->rincianRealisasi as $detail) {
            if ((float) $detail->nilai_diajukan <= 0) {
                continue;
            }

            $summary['items']++;
            [$source, $benchmark] = $this->benchmark($travel, $detail);

            if ($source === 'pmk' && $benchmark !== null) {
                $difference = (float) $detail->nilai_diajukan - $benchmark;
                if ($difference > 0) {
                    $summary['over']++;
                    $summary['over_amount'] += $difference;
                } else {
                    $summary['within']++;
                }
            } elseif ($source === 'legacy') {
                $summary['legacy']++;
            } else {
                $summary['unavailable']++;
            }
        }

        return $summary;
    }

    /** @return array{0: string, 1: ?float} */
    private function benchmark(PerjalananDinas $travel, RealisasiRincian $detail): array
    {
        if ($detail->kategori === RealisasiRincian::CATEGORY_HOTEL) {
            if ($travel->hotel_rate_source === 'pmk') {
                return ['pmk', (float) $travel->batas_hotel_per_hari_snapshot * max(0, (int) $travel->hotel_nights_snapshot)];
            }

            return ['legacy', null];
        }

        if ($travel->transport_rate_source === 'pmk_ground') {
            if (in_array($detail->kode, [
                RealisasiRincian::CODE_GROUND_OUTBOUND,
                RealisasiRincian::CODE_GROUND_RETURN,
            ], true)) {
                return ['pmk', (float) $travel->ground_transport_one_way_snapshot];
            }

            return ['unavailable', null];
        }

        if ($travel->transport_rate_source === 'pmk_air') {
            $source = (string) ($detail->benchmark_source ?? 'unavailable');

            return match ($source) {
                'pmk' => ['pmk', $detail->benchmark_amount_snapshot === null ? null : (float) $detail->benchmark_amount_snapshot],
                'legacy' => ['legacy', $detail->benchmark_amount_snapshot === null ? null : (float) $detail->benchmark_amount_snapshot],
                default => ['unavailable', null],
            };
        }

        return ['legacy', null];
    }

    private function emptySummary(): array
    {
        return [
            'items' => 0,
            'within' => 0,
            'over' => 0,
            'legacy' => 0,
            'unavailable' => 0,
            'over_amount' => 0.0,
            'travels_with_exceptions' => 0,
            'compliance_rate' => 0,
        ];
    }
}
