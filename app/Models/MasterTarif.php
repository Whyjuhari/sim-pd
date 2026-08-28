<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterTarif extends Model
{
    public $timestamps = false;

    protected $table = 'master_tarif';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uang_saku_per_hari' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
