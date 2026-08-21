<?php

namespace App\Services;

use App\Models\MasterTarif;
use App\Models\Setting;
use DomainException;

class TravelCostCalculator
{
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

        return ($rates['daily_allowance'] * $days)
            + ($rates['hotel_per_day'] * $days)
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

    public function verificationRecommendation(array $travel): array
    {
        $hasCompleteSnapshot = ($travel['uang_harian_per_hari_snapshot'] ?? null) !== null
            && ($travel['batas_hotel_per_hari_snapshot'] ?? null) !== null
            && ($travel['batas_transport_snapshot'] ?? null) !== null;
        $rates = $hasCompleteSnapshot
            ? null
            : $this->rates(
                (string) $travel['kota_tujuan'],
                (string) ($travel['angkutan'] ?? '')
            );
        $days = max(1, (int) $travel['lama_hari']);
        $dailyRate = (float) ($travel['uang_harian_per_hari_snapshot'] ?? $rates['daily_allowance']);
        $hotelPerDay = (float) ($travel['batas_hotel_per_hari_snapshot'] ?? $rates['hotel_per_day']);
        $transportLimit = (float) ($travel['batas_transport_snapshot'] ?? $rates['transport_limit']);
        $hotelLimit = $hotelPerDay * $days;

        return [
            'hotel_limit' => $hotelLimit,
            'transport_limit' => $transportLimit,
            'hotel_recommended' => min((float) $travel['biaya_hotel_real'], $hotelLimit),
            'transport_recommended' => min((float) $travel['biaya_tiket_real'], $transportLimit),
            'hotel_per_day' => $hotelPerDay,
            'daily_allowance_per_day' => $dailyRate,
            'daily_allowance_total' => $dailyRate * $days,
        ];
    }
}
