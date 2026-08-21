<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaporanPerjadin extends Model
{

    protected $table = 'laporan_perjadin';
    protected $fillable = [
        'perjalanan_dinas_id',
        'hasil_pelaksanaan',
        'kesimpulan',
        'tanggal_laporan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_laporan' => 'date',
        ];
    }

    public function perjalananDinas(): BelongsTo
    {
        return $this->belongsTo(
            PerjalananDinas::class,
            'perjalanan_dinas_id'
        );
    }

    public function dokumentasi(): HasMany
    {
        return $this->hasMany(
            LaporanPerjadinDokumentasi::class,
            'laporan_perjadin_id'
        )->orderBy('urutan');
    }
}
