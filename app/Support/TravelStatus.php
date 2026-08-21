<?php

namespace App\Support;

use App\Models\PerjalananDinas;

class TravelStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            PerjalananDinas::STATUS_DRAFT => 'Draft SPT',
            PerjalananDinas::STATUS_READY => 'Siap Berjalan',
            PerjalananDinas::STATUS_PENDING => 'Menunggu Verifikasi',
            PerjalananDinas::STATUS_APPROVED => 'Selesai',
            PerjalananDinas::STATUS_REJECTED => 'Perlu Revisi',
            default => ucfirst((string) $status),
        };
    }
}
