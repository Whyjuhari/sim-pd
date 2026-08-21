<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerjalananDinasStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'perjalanan_dinas_status_histories';

    protected $fillable = [
        'perjalanan_dinas_id',
        'actor_id',
        'from_status',
        'to_status',
        'note',
    ];

    public function perjalananDinas(): BelongsTo
    {
        return $this->belongsTo(PerjalananDinas::class, 'perjalanan_dinas_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
