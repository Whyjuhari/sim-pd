<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalTransportRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(AirTransportRegulation::class, 'air_transport_regulation_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
