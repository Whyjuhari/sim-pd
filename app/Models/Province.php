<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function masterTariffs(): HasMany
    {
        return $this->hasMany(MasterTarif::class);
    }

    public function hotelRates(): HasMany
    {
        return $this->hasMany(HotelRate::class);
    }

    public function groundTransportRates(): HasMany
    {
        return $this->hasMany(GroundTransportRate::class);
    }
}
