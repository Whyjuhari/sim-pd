<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroundTransportRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['one_way_amount' => 'decimal:2'];
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(GroundTransportRegulation::class, 'ground_transport_regulation_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
