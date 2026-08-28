<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealisasiRincian extends Model
{
    public const CATEGORY_HOTEL = 'hotel';
    public const CATEGORY_TRANSPORT = 'transportasi';

    public const CODE_HOTEL = 'hotel';
    public const CODE_LOCAL_DEPARTURE = 'transport_lokal_berangkat';
    public const CODE_FLIGHT_TICKET = 'tiket_pesawat';
    public const CODE_DESTINATION_OUTBOUND = 'transport_tujuan_berangkat';
    public const CODE_DESTINATION_RETURN = 'transport_tujuan_pulang';
    public const CODE_LOCAL_RETURN = 'transport_lokal_pulang';
    public const CODE_GROUND_TRANSPORT = 'transport_darat';
    public const CODE_GROUND_OUTBOUND = 'transport_darat_berangkat';
    public const CODE_GROUND_RETURN = 'transport_darat_pulang';

    protected $table = 'realisasi_rincian';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'nilai_diajukan' => 'decimal:2',
            'nilai_disetujui' => 'decimal:2',
            'benchmark_amount_snapshot' => 'decimal:2',
            'office_route_confirmed' => 'boolean',
            'non_private_vehicle_confirmed' => 'boolean',
            'is_preset' => 'boolean',
            'urutan' => 'integer',
        ];
    }

    public function perjalananDinas(): BelongsTo
    {
        return $this->belongsTo(PerjalananDinas::class, 'perjalanan_dinas_id');
    }

    public function bukti(): HasMany
    {
        return $this->hasMany(BuktiRealisasi::class, 'realisasi_rincian_id')
            ->orderBy('urutan');
    }
}
