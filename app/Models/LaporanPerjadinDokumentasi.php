<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LaporanPerjadinDokumentasi extends Model
{
    protected $table = 'laporan_perjadin_dokumentasi';

    protected $fillable = [
        'path',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(
            LaporanPerjadin::class,
            'laporan_perjadin_id'
        );
    }

    public function absolutePath(): ?string
    {
        if (! preg_match(
            '#\Areport-documentation/[0-9a-f-]+\.jpg\z#D',
            (string) $this->path
        )) {
            return null;
        }

        if (! Storage::disk('local')->exists($this->path)) {
            return null;
        }

        return Storage::disk('local')->path($this->path);
    }
}
