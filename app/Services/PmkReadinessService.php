<?php

namespace App\Services;

use App\Models\AirTransportRegulation;
use App\Models\DailyAllowanceRegulation;
use App\Models\GroundTransportRegulation;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use Illuminate\Support\Collection;

class PmkReadinessService
{
    public function __construct(
        private readonly AirTransportCsvService $airTransport
    ) {}

    public function summary(int $year): array
    {
        $daily = DailyAllowanceRegulation::query()->activeForYear($year)->withCount('rates')->first();
        $hotel = HotelRegulation::query()->activeForYear($year)->withCount('rates')->first();
        $ground = GroundTransportRegulation::query()->activeForYear($year)->withCount('rates')->first();
        $air = AirTransportRegulation::query()->activeForYear($year)
            ->withCount(['terminalRates', 'airfareRates'])
            ->first();

        $datasets = collect([
            $this->dataset('Uang Harian', $daily, (int) ($daily?->rates_count ?? 0), 38),
            $this->dataset('Penginapan', $hotel, (int) ($hotel?->rates_count ?? 0), 38),
            $this->dataset('Darat', $ground, (int) ($ground?->rates_count ?? 0), GroundTransportCsvService::EXPECTED_ROUTES),
            $this->dataset(
                'Udara',
                $air,
                (int) ($air?->terminal_rates_count ?? 0) + (int) ($air?->airfare_rates_count ?? 0),
                AirTransportCsvService::EXPECTED_TERMINAL_RATES + AirTransportCsvService::EXPECTED_AIRFARE_ROUTES
            ),
        ]);

        $destinations = MasterTarif::query()
            ->orderBy('kota_tujuan')
            ->get(['id', 'kota_tujuan', 'province_id', 'airfare_city', 'airfare_city_key', 'ground_transport_source']);
        $terminalProvinceIds = $air
            ? $air->terminalRates()->pluck('province_id')->map(fn ($id): int => (int) $id)->flip()
            : collect();
        $airfarePairs = $air
            ? $air->airfareRates()->get(['origin_key', 'destination_key'])->flatMap(fn ($rate): array => [
                $rate->origin_key.'|'.$rate->destination_key => true,
                $rate->destination_key.'|'.$rate->origin_key => true,
            ])
            : collect();
        $originKey = $this->airTransport->normalizeCity(
            (string) config('sim_pd.air_transport.default_origin_airfare_city', 'MAKASSAR')
        );

        $missingProvince = $destinations->whereNull('province_id')->pluck('kota_tujuan')->values();
        $missingAirport = $destinations->filter(fn ($item): bool => trim((string) $item->airfare_city) === '')
            ->pluck('kota_tujuan')->values();
        $terminalUnavailable = $destinations->filter(fn ($item): bool =>
            $item->province_id !== null && ! $terminalProvinceIds->has((int) $item->province_id)
        )->pluck('kota_tujuan')->values();
        $airfareFallback = $destinations->filter(function ($item) use ($airfarePairs, $originKey): bool {
            $destinationKey = trim((string) $item->airfare_city_key);

            return $destinationKey !== '' && $destinationKey !== $originKey
                && ! $airfarePairs->has($originKey.'|'.$destinationKey);
        })->pluck('kota_tujuan')->values();
        $groundLegacy = $destinations->filter(fn ($item): bool => $item->ground_transport_source !== 'pmk')
            ->pluck('kota_tujuan')->values();

        return [
            'year' => $year,
            'ready' => $datasets->every('ready') && $missingProvince->isEmpty(),
            'datasets' => $datasets,
            'dataset_ready_count' => $datasets->where('ready', true)->count(),
            'destination_count' => $destinations->count(),
            'mappings' => [
                'missing_province' => $this->issue($missingProvince),
                'missing_airport' => $this->issue($missingAirport),
                'terminal_unavailable' => $this->issue($terminalUnavailable),
                'airfare_fallback' => $this->issue($airfareFallback),
                'ground_legacy' => $this->issue($groundLegacy),
            ],
        ];
    }

    public function availableYears(): Collection
    {
        return collect([
            ...DailyAllowanceRegulation::query()->pluck('fiscal_year')->all(),
            ...HotelRegulation::query()->pluck('fiscal_year')->all(),
            ...GroundTransportRegulation::query()->pluck('fiscal_year')->all(),
            ...AirTransportRegulation::query()->pluck('fiscal_year')->all(),
            now()->year,
        ])->map(fn ($year): int => (int) $year)->unique()->sortDesc()->values();
    }

    private function dataset(string $label, $regulation, int $actual, int $expected): array
    {
        return [
            'label' => $label,
            'active' => $regulation !== null,
            'ready' => $regulation !== null && $actual === $expected,
            'revision' => $regulation?->revision,
            'actual' => $actual,
            'expected' => $expected,
        ];
    }

    private function issue(Collection $destinations): array
    {
        return [
            'count' => $destinations->count(),
            'examples' => $destinations->take(5)->values()->all(),
        ];
    }
}
