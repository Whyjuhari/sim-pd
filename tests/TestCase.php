<?php

namespace Tests;

use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use App\Services\RealizationDetailService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function realizationPayload(
        PerjalananDinas $travel,
        float $hotel,
        float $transport,
        array $hotelEvidence = [],
        array $transportEvidence = [],
        array $additional = []
    ): array {
        $travel->load('rincianRealisasi');

        if ($travel->rincianRealisasi->isNotEmpty()) {
            $definitions = $travel->rincianRealisasi->map(fn (RealisasiRincian $detail): array => [
                'key' => 'detail_'.$detail->id,
                'id' => $detail->id,
                'category' => $detail->kategori,
                'code' => $detail->kode,
                'description' => $detail->uraian,
            ]);
        } else {
            $definitions = collect(app(RealizationDetailService::class)->presets($travel))->map(fn (array $preset): array => [
                'key' => $preset['key'],
                'id' => null,
                'category' => $preset['category'],
                'code' => $preset['code'],
                'description' => $preset['description'],
            ]);
        }

        $hotelTarget = $definitions->firstWhere('category', RealisasiRincian::CATEGORY_HOTEL);
        $transportTarget = $definitions->firstWhere('code', RealisasiRincian::CODE_FLIGHT_TICKET)
            ?? $definitions->firstWhere('category', RealisasiRincian::CATEGORY_TRANSPORT);

        $items = [];
        foreach ($definitions as $definition) {
            $isHotelTarget = $hotelTarget && $definition['key'] === $hotelTarget['key'];
            $isTransportTarget = $transportTarget && $definition['key'] === $transportTarget['key'];
            $items[$definition['key']] = [
                'id' => $definition['id'],
                'code' => $definition['code'],
                'description' => $definition['description'],
                'amount' => $isHotelTarget ? $hotel : ($isTransportTarget ? $transport : 0),
            ];
            if ($isHotelTarget && $hotelEvidence !== []) {
                $items[$definition['key']]['evidence'] = $hotelEvidence;
            }
            if ($isTransportTarget && $transportEvidence !== []) {
                $items[$definition['key']]['evidence'] = $transportEvidence;
            }
        }

        return [
            'id' => $travel->id,
            'items' => $items,
            ...$additional,
        ];
    }

    protected function approvalPayload(
        PerjalananDinas $travel,
        ?string $note = null
    ): array {
        return [
            'id' => $travel->id,
            'action' => 'approve',
            'catatan' => $note,
        ];
    }
}
