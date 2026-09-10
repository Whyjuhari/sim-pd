<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SptSrikandiWorkflow extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_WAITING = 'waiting_srikandi';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PUBLISHED = 'published';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'official_size_bytes' => 'integer',
            'submitted_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function travels(): HasMany
    {
        return $this->hasMany(PerjalananDinas::class, 'spt_group_id', 'spt_group_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function label(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_WAITING => 'Menunggu Srikandi',
            self::STATUS_UPLOADED => 'Siap Diterbitkan',
            self::STATUS_PUBLISHED => 'Sudah Diterbitkan',
            default => 'Status tidak dikenal',
        };
    }
}
