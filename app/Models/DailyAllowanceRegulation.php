<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyAllowanceRegulation extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'revision' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DailyAllowanceRate::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function scopeActiveForYear(Builder $query, int $year): Builder
    {
        return $query
            ->where('fiscal_year', $year)
            ->where('status', self::STATUS_ACTIVE);
    }
}
