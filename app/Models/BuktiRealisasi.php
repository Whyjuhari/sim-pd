<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuktiRealisasi extends Model
{
    public const TYPE_HOTEL = 'hotel';
    public const TYPE_TRANSPORT = 'transportasi';

    protected $table = 'bukti_realisasi';

    protected $fillable = [
        'perjalanan_dinas_id',
        'realisasi_rincian_id',
        'jenis',
        'path',
        'nama_asli',
        'mime_type',
        'ukuran',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'ukuran' => 'integer',
            'urutan' => 'integer',
        ];
    }

    public function perjalananDinas(): BelongsTo
    {
        return $this->belongsTo(PerjalananDinas::class, 'perjalanan_dinas_id');
    }

    public function rincianRealisasi(): BelongsTo
    {
        return $this->belongsTo(RealisasiRincian::class, 'realisasi_rincian_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
