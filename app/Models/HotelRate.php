<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelRate extends Model
{
    public const GROUP_ESELON_IV_GOLONGAN_I_III = 'eselon_iv_golongan_iii_ii_i';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public static function groupLabel(): string
    {
        return 'Pejabat Eselon IV/Golongan III/II/I';
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(HotelRegulation::class, 'hotel_regulation_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
