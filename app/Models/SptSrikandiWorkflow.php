<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SptSrikandiWorkflow extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_WAITING = 'waiting_srikandi';
    public const STATUS_REVISION = 'revision_required';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PUBLISHED = 'published';

    public const ACTIONABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REVISION, self::STATUS_UPLOADED];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Belum Dikirim',
            self::STATUS_WAITING => 'Menunggu SPT Selesai',
            self::STATUS_REVISION => 'Perlu Diperbaiki',
            self::STATUS_UPLOADED => 'Siap Dicek',
            self::STATUS_PUBLISHED => 'Sudah Dibagikan',
        ];
    }

    public function badgeTone(): string
    {
        return match ($this->status) {
            self::STATUS_REVISION => 'warning',
            self::STATUS_UPLOADED => 'primary',
            self::STATUS_PUBLISHED => 'success',
            default => 'secondary',
        };
    }

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

    public function versions(): HasMany
    {
        return $this->hasMany(SptSrikandiVersion::class, 'workflow_id')->orderBy('version_number');
    }

    public function latestVersion(): ?SptSrikandiVersion
    {
        if ($this->relationLoaded('versions')) {
            return $this->versions->sortByDesc('version_number')->first();
        }

        return $this->versions()->orderByDesc('version_number')->first();
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
        return self::statusLabels()[$this->status] ?? 'Status tidak dikenal';
    }
}
