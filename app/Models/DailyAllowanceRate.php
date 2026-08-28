<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAllowanceRate extends Model
{
    public const CATEGORY_OUTSIDE_CITY = 'outside_city';
    public const CATEGORY_INSIDE_CITY_OVER_8_HOURS = 'inside_city_over_8_hours';
    public const CATEGORY_TRAINING = 'training';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'outside_city' => 'decimal:2',
            'inside_city_over_8_hours' => 'decimal:2',
            'training' => 'decimal:2',
        ];
    }

    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_OUTSIDE_CITY => 'Luar Kota',
            self::CATEGORY_INSIDE_CITY_OVER_8_HOURS => 'Dalam Kota Lebih dari 8 Jam',
            self::CATEGORY_TRAINING => 'Diklat',
        ];
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(DailyAllowanceRegulation::class, 'daily_allowance_regulation_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function amountFor(string $category): float
    {
        if (! array_key_exists($category, self::categoryLabels())) {
            throw new \DomainException('Kategori uang harian tidak dikenal.');
        }

        return (float) $this->{$category};
    }
}
