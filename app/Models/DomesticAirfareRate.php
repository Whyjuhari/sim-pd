<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomesticAirfareRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_number' => 'integer',
            'business_amount' => 'decimal:2',
            'economy_amount' => 'decimal:2',
        ];
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(AirTransportRegulation::class, 'air_transport_regulation_id');
    }
}
