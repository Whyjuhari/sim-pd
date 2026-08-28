<?php

namespace App\Services;

use App\Models\DailyAllowanceRate;
use App\Models\DailyAllowanceRegulation;
use App\Models\GroundTransportRegulation;
use App\Models\AirTransportRegulation;
use App\Models\HotelRate;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use DomainException;

class TravelCostCalculator
{
    public function __construct(
        private readonly GroundTransportCsvService $groundTransport,
        private readonly AirTransportCsvService $airTransport
    ) {}

    public function settings(): array
    {
        return Setting::query()
            ->whereIn('nama_setting', ['batas_hotel', 'pagu_tiket', 'batas_transport_darat'])
            ->pluck('nilai_setting', 'nama_setting')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    public function dailyAllowance(string $destination): float
    {
        $value = MasterTarif::query()
            ->where('kota_tujuan', $destination)
            ->value('uang_saku_per_hari');

        if ($value === null) {
            throw new DomainException('Tarif kota tujuan tidak ditemukan.');
        }

        return (float) $value;
    }

    public function estimate(string $destination, int $days, string $transport): float
    {
        $rates = $this->rates($destination, $transport);

        $hotelTotal = $days > 1
            ? $rates['hotel_per_day'] * $days
            : 0;

        return ($rates['daily_allowance'] * $days)
            + $hotelTotal
            + $rates['transport_limit'];
    }

    /** @return array{daily_allowance: float, hotel_per_day: float, transport_limit: float} */
    public function rates(string $destination, string $transport): array
    {
        $settings = $this->settings();

        return [
            'daily_allowance' => $this->dailyAllowance($destination),
            'hotel_per_day' => (float) ($settings['batas_hotel'] ?? 0),
            'transport_limit' => (float) ($transport === 'Pesawat Udara'
                ? ($settings['pagu_tiket'] ?? 0)
                : ($settings['batas_transport_darat'] ?? 0)),
        ];
    }

    /**
     * Tarif untuk penyimpanan SPT. SPT lama yang belum memakai PMK selalu
     * dipertahankan pada sumber legacy, sedangkan SPT PMK mempertahankan
     * versi asal selama tahun anggarannya tidak berubah.
     *
     * @return array{
     *   daily_allowance: float,
     *   hotel_per_day: float,
     *   transport_limit: float,
     *   daily_allowance_source: string,
     *   daily_allowance_category: ?string,
     *   daily_allowance_regulation_id: ?int,
     *   daily_allowance_province_id: ?int
     * }
     */
    public function ratesForOrder(
        string $destination,
        string $transport,
        string $departureDate,
        ?string $category,
        ?PerjalananDinas $existing = null,
        int $days = 1,
        string $origin = 'Pangkep'
    ): array {
        $settings = $this->settings();
        $daily = $this->resolveDailyAllowance($destination, $departureDate, $category, $existing);
        $hotel = $this->resolveHotelRate(
            $destination,
            $departureDate,
            max(1, $days),
            $existing,
            $settings
        );
        $transportRates = $transport === 'Pesawat Udara'
            ? $this->resolveAirTransportRate($origin, $destination, $departureDate, $existing, $settings)
            : $this->resolveGroundTransportRate($origin, $destination, $transport, $departureDate, $existing, $settings);

        $destinationTariff = MasterTarif::query()
            ->where('kota_tujuan', $destination)
            ->first();
        if (
            $destinationTariff?->ground_transport_source === 'pmk'
            && ($daily['daily_allowance_source'] !== 'pmk' || $hotel['hotel_rate_source'] !== 'pmk')
        ) {
            throw new DomainException(
                'Dataset uang harian dan hotel PMK untuk tujuan ini harus aktif sebelum SPT dibuat.'
            );
        }

        return [
            ...$daily,
            ...$hotel,
            ...$transportRates,
        ];
    }

    public function verificationRecommendation(array $travel): array
    {
        $hasCompleteSnapshot = ($travel['uang_harian_per_hari_snapshot'] ?? null) !== null
            && ($travel['batas_hotel_per_hari_snapshot'] ?? null) !== null
            && ($travel['batas_transport_snapshot'] ?? null) !== null;
        $rates = null;
        if (! $hasCompleteSnapshot) {
            try {
                $rates = $this->rates(
                    (string) $travel['kota_tujuan'],
                    (string) ($travel['angkutan'] ?? '')
                );
            } catch (DomainException) {
                $rates = [
                    'daily_allowance' => 0.0,
                    'hotel_per_day' => 0.0,
                    'transport_limit' => 0.0,
                ];
            }
        }
        $days = max(1, (int) $travel['lama_hari']);
        $dailyRate = (float) ($travel['uang_harian_per_hari_snapshot'] ?? $rates['daily_allowance']);
        $hotelPerDay = (float) ($travel['batas_hotel_per_hari_snapshot'] ?? $rates['hotel_per_day']);
        $transportLimit = (float) ($travel['batas_transport_snapshot'] ?? $rates['transport_limit']);
        $transportSource = (string) ($travel['transport_rate_source'] ?? 'legacy');
        $hotelSource = (string) ($travel['hotel_rate_source'] ?? 'legacy');
        $hotelNights = $hotelSource === 'pmk'
            ? max(0, (int) ($travel['hotel_nights_snapshot'] ?? ($days - 1)))
            : ($days > 1 ? $days : 0);
        $hotelLimit = $hotelPerDay * $hotelNights;

        return [
            'hotel_limit' => $hotelLimit,
            'transport_limit' => $transportLimit,
            'hotel_recommended' => min((float) $travel['biaya_hotel_real'], $hotelLimit),
            'transport_recommended' => in_array($transportSource, ['pmk_ground', 'pmk_air'], true)
                ? (float) $travel['biaya_tiket_real']
                : min((float) $travel['biaya_tiket_real'], $transportLimit),
            'transport_rate_source' => $transportSource,
            'ground_transport_one_way' => (float) ($travel['ground_transport_one_way_snapshot'] ?? 0),
            'ground_transport_origin' => $travel['ground_transport_origin'] ?? null,
            'ground_transport_destination' => $travel['ground_transport_destination'] ?? null,
            'terminal_origin_one_way' => (float) ($travel['terminal_origin_one_way_snapshot'] ?? 0),
            'terminal_destination_one_way' => (float) ($travel['terminal_destination_one_way_snapshot'] ?? 0),
            'terminal_origin_source' => $travel['terminal_origin_source'] ?? null,
            'terminal_destination_source' => $travel['terminal_destination_source'] ?? null,
            'airfare_economy_pp' => (float) ($travel['airfare_economy_pp_snapshot'] ?? 0),
            'airfare_rate_source' => $travel['airfare_rate_source'] ?? null,
            'air_origin_city' => $travel['air_origin_city'] ?? null,
            'air_destination_city' => $travel['air_destination_city'] ?? null,
            'hotel_per_day' => $hotelPerDay,
            'hotel_nights' => $hotelNights,
            'hotel_rate_source' => $hotelSource,
            'hotel_rate_group' => $travel['hotel_rate_group'] ?? null,
            'daily_allowance_per_day' => $dailyRate,
            'daily_allowance_total' => $dailyRate * $days,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function resolveGroundTransportRate(
        string $origin,
        string $destination,
        string $transport,
        string $departureDate,
        ?PerjalananDinas $existing,
        array $settings
    ): array {
        if ($existing && $existing->transport_rate_source !== 'pmk_ground') {
            return $this->legacyTransportRate((float) ($settings['batas_transport_darat'] ?? 0));
        }

        $year = CarbonImmutable::parse($departureDate)->year;
        $regulation = null;
        if (
            $existing
            && $existing->transport_rate_source === 'pmk_ground'
            && $existing->ground_transport_regulation_id
        ) {
            $original = GroundTransportRegulation::query()
                ->find($existing->ground_transport_regulation_id);
            if ($original && $original->fiscal_year === $year) {
                $regulation = $original;
            }
        }

        $regulation ??= GroundTransportRegulation::query()->activeForYear($year)->first();
        if (! $regulation) {
            return $this->legacyTransportRate((float) ($settings['batas_transport_darat'] ?? 0));
        }

        $rate = $this->groundTransport->findRate($regulation, $origin, $destination);
        if (! $rate) {
            return $this->legacyTransportRate((float) ($settings['batas_transport_darat'] ?? 0));
        }

        $oneWay = (float) $rate->one_way_amount;

        return [
            'transport_limit' => $oneWay * 2,
            'transport_rate_source' => 'pmk_ground',
            'ground_transport_regulation_id' => $regulation->id,
            'ground_transport_province_id' => $rate->province_id,
            'ground_transport_origin' => trim($origin),
            'ground_transport_destination' => trim($destination),
            'ground_transport_one_way' => $oneWay,
            ...$this->emptyAirTransportSnapshot(),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function legacyTransportRate(float $limit): array
    {
        return [
            'transport_limit' => $limit,
            'transport_rate_source' => 'legacy',
            'ground_transport_regulation_id' => null,
            'ground_transport_province_id' => null,
            'ground_transport_origin' => null,
            'ground_transport_destination' => null,
            'ground_transport_one_way' => null,
            ...$this->emptyAirTransportSnapshot(),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function resolveAirTransportRate(
        string $origin,
        string $destination,
        string $departureDate,
        ?PerjalananDinas $existing,
        array $settings
    ): array {
        $legacyTicket = (float) ($settings['pagu_tiket'] ?? 0);
        if ($existing && $existing->transport_rate_source !== 'pmk_air') {
            return $this->legacyTransportRate($legacyTicket);
        }

        $year = CarbonImmutable::parse($departureDate)->year;
        if ($existing && $existing->transport_rate_source === 'pmk_air' && $existing->air_transport_regulation_id) {
            $original = AirTransportRegulation::query()->find($existing->air_transport_regulation_id);
            if ($original && $original->fiscal_year === $year) {
                return [
                    'transport_limit' => (float) $existing->batas_transport_snapshot,
                    'transport_rate_source' => 'pmk_air',
                    'ground_transport_regulation_id' => null,
                    'ground_transport_province_id' => null,
                    'ground_transport_origin' => null,
                    'ground_transport_destination' => null,
                    'ground_transport_one_way' => null,
                    'air_transport_regulation_id' => $original->id,
                    'air_origin_province_id' => $existing->air_origin_province_id,
                    'air_destination_province_id' => $existing->air_destination_province_id,
                    'air_origin_city' => $existing->air_origin_city,
                    'air_destination_city' => $existing->air_destination_city,
                    'airfare_class' => $existing->airfare_class ?: 'economy',
                    'terminal_origin_source' => $existing->terminal_origin_source,
                    'terminal_origin_one_way' => (float) ($existing->terminal_origin_one_way_snapshot ?? 0),
                    'terminal_destination_source' => $existing->terminal_destination_source,
                    'terminal_destination_one_way' => (float) ($existing->terminal_destination_one_way_snapshot ?? 0),
                    'airfare_rate_source' => $existing->airfare_rate_source,
                    'airfare_business_pp' => $existing->airfare_business_pp_snapshot === null ? null : (float) $existing->airfare_business_pp_snapshot,
                    'airfare_economy_pp' => (float) ($existing->airfare_economy_pp_snapshot ?? $legacyTicket),
                ];
            }
        }

        $regulation = AirTransportRegulation::query()->activeForYear($year)->first();
        if (! $regulation) {
            return $this->legacyTransportRate($legacyTicket);
        }

        $originTariff = MasterTarif::query()->with('province')->where('kota_tujuan', $origin)->first();
        $destinationTariff = MasterTarif::query()->with('province')->where('kota_tujuan', $destination)->first();
        $defaultOrigin = (string) config('sim_pd.air_transport.default_origin_location', 'Pangkep');
        if ($this->airTransport->normalizeCity($origin) === $this->airTransport->normalizeCity($defaultOrigin)) {
            $originProvince = \App\Models\Province::query()
                ->where('name', (string) config('sim_pd.air_transport.default_origin_province', 'SULAWESI SELATAN'))
                ->first();
            $originCity = (string) config('sim_pd.air_transport.default_origin_airfare_city', 'MAKASSAR');
        } else {
            $originProvince = $originTariff?->province;
            $originCity = (string) ($originTariff?->airfare_city ?? '');
        }
        $destinationProvince = $destinationTariff?->province;
        $destinationCity = (string) ($destinationTariff?->airfare_city ?? '');

        $originTerminalRate = $this->airTransport->findTerminalRate($regulation, $originProvince?->id);
        $destinationTerminalRate = $this->airTransport->findTerminalRate($regulation, $destinationProvince?->id);
        $airfareRate = $this->airTransport->findAirfareRate($regulation, $originCity, $destinationCity);
        $originTerminal = (float) ($originTerminalRate?->amount ?? 0);
        $destinationTerminal = (float) ($destinationTerminalRate?->amount ?? 0);
        $economy = (float) ($airfareRate?->economy_amount ?? $legacyTicket);

        return [
            'transport_limit' => ($originTerminal * 2) + ($destinationTerminal * 2) + $economy,
            'transport_rate_source' => 'pmk_air',
            'ground_transport_regulation_id' => null,
            'ground_transport_province_id' => null,
            'ground_transport_origin' => null,
            'ground_transport_destination' => null,
            'ground_transport_one_way' => null,
            'air_transport_regulation_id' => $regulation->id,
            'air_origin_province_id' => $originProvince?->id,
            'air_destination_province_id' => $destinationProvince?->id,
            'air_origin_city' => $originCity !== '' ? $originCity : null,
            'air_destination_city' => $destinationCity !== '' ? $destinationCity : null,
            'airfare_class' => 'economy',
            'terminal_origin_source' => $originTerminalRate ? 'pmk' : 'unavailable',
            'terminal_origin_one_way' => $originTerminal,
            'terminal_destination_source' => $destinationTerminalRate ? 'pmk' : 'unavailable',
            'terminal_destination_one_way' => $destinationTerminal,
            'airfare_rate_source' => $airfareRate ? 'pmk' : 'legacy',
            'airfare_business_pp' => $airfareRate ? (float) $airfareRate->business_amount : null,
            'airfare_economy_pp' => $economy,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function emptyAirTransportSnapshot(): array
    {
        return [
            'air_transport_regulation_id' => null,
            'air_origin_province_id' => null,
            'air_destination_province_id' => null,
            'air_origin_city' => null,
            'air_destination_city' => null,
            'airfare_class' => null,
            'terminal_origin_source' => null,
            'terminal_origin_one_way' => null,
            'terminal_destination_source' => null,
            'terminal_destination_one_way' => null,
            'airfare_rate_source' => null,
            'airfare_business_pp' => null,
            'airfare_economy_pp' => null,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function resolveHotelRate(
        string $destination,
        string $departureDate,
        int $days,
        ?PerjalananDinas $existing,
        array $settings
    ): array {
        $year = CarbonImmutable::parse($departureDate)->year;

        if ($existing && $existing->hotel_rate_source !== 'pmk') {
            return $this->legacyHotelRate($settings, $days);
        }

        $regulation = null;
        if ($existing && $existing->hotel_rate_source === 'pmk' && $existing->hotel_regulation_id) {
            $original = HotelRegulation::query()->find($existing->hotel_regulation_id);
            if ($original && $original->fiscal_year === $year) {
                $regulation = $original;
            }
        }

        $regulation ??= HotelRegulation::query()->activeForYear($year)->first();

        if (! $regulation) {
            $cutoverYear = HotelRegulation::query()->whereNotNull('activated_at')->min('fiscal_year');
            if ($cutoverYear !== null && $year >= (int) $cutoverYear) {
                throw new DomainException("Tarif hotel PMK untuk tahun {$year} belum diaktifkan.");
            }

            return $this->legacyHotelRate($settings, $days);
        }

        $destinationTariff = MasterTarif::query()->with('province')
            ->where('kota_tujuan', $destination)->first();
        if (! $destinationTariff?->province) {
            throw new DomainException('Provinsi kota tujuan belum dipetakan untuk tarif hotel PMK.');
        }

        $rate = HotelRate::query()
            ->where('hotel_regulation_id', $regulation->id)
            ->where('province_id', $destinationTariff->province_id)
            ->where('rate_group', HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III)
            ->first();
        if (! $rate) {
            throw new DomainException('Tarif hotel PMK untuk provinsi tujuan belum tersedia.');
        }

        return [
            'hotel_per_day' => (float) $rate->amount,
            'hotel_rate_source' => 'pmk',
            'hotel_regulation_id' => $regulation->id,
            'hotel_province_id' => $destinationTariff->province_id,
            'hotel_rate_group' => HotelRate::GROUP_ESELON_IV_GOLONGAN_I_III,
            'hotel_nights' => max(0, $days - 1),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function legacyHotelRate(array $settings, int $days): array
    {
        return [
            'hotel_per_day' => (float) ($settings['batas_hotel'] ?? 0),
            'hotel_rate_source' => 'legacy',
            'hotel_regulation_id' => null,
            'hotel_province_id' => null,
            'hotel_rate_group' => null,
            'hotel_nights' => max(1, $days),
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function resolveDailyAllowance(
        string $destination,
        string $departureDate,
        ?string $category,
        ?PerjalananDinas $existing
    ): array {
        $year = CarbonImmutable::parse($departureDate)->year;

        if ($existing && $existing->daily_allowance_source !== 'pmk') {
            return $this->legacyDailyAllowance($destination);
        }

        $regulation = null;
        if (
            $existing
            && $existing->daily_allowance_source === 'pmk'
            && $existing->daily_allowance_regulation_id
        ) {
            $original = DailyAllowanceRegulation::query()->find($existing->daily_allowance_regulation_id);
            if ($original && $original->fiscal_year === $year) {
                $regulation = $original;
            }
        }

        $regulation ??= DailyAllowanceRegulation::query()->activeForYear($year)->first();

        if (! $regulation) {
            $cutoverYear = DailyAllowanceRegulation::query()
                ->whereNotNull('activated_at')
                ->min('fiscal_year');

            if ($cutoverYear !== null && $year >= (int) $cutoverYear) {
                throw new DomainException("Tarif uang harian PMK untuk tahun {$year} belum diaktifkan.");
            }

            return $this->legacyDailyAllowance($destination);
        }

        if (! $category || ! array_key_exists($category, DailyAllowanceRate::categoryLabels())) {
            throw new DomainException('Kategori uang harian PMK wajib dipilih.');
        }

        $tariff = MasterTarif::query()->with('province')->where('kota_tujuan', $destination)->first();
        if (! $tariff?->province) {
            throw new DomainException('Provinsi kota tujuan belum dipetakan pada Master Anggaran.');
        }

        $rate = DailyAllowanceRate::query()
            ->where('daily_allowance_regulation_id', $regulation->id)
            ->where('province_id', $tariff->province_id)
            ->first();
        if (! $rate) {
            throw new DomainException('Tarif PMK untuk provinsi tujuan belum tersedia.');
        }

        return [
            'daily_allowance' => $rate->amountFor($category),
            'daily_allowance_source' => 'pmk',
            'daily_allowance_category' => $category,
            'daily_allowance_regulation_id' => $regulation->id,
            'daily_allowance_province_id' => $tariff->province_id,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function legacyDailyAllowance(string $destination): array
    {
        return [
            'daily_allowance' => $this->dailyAllowance($destination),
            'daily_allowance_source' => 'legacy',
            'daily_allowance_category' => null,
            'daily_allowance_regulation_id' => null,
            'daily_allowance_province_id' => null,
        ];
    }
}
