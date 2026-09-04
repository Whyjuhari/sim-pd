<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SptTemplate extends Model
{
    public $timestamps = false;

    protected $table = 'spt_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function perjalananDinas(): HasMany
    {
        return $this->hasMany(PerjalananDinas::class, 'spt_template_id');
    }

    public function absolutePath(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk('local')->path($this->file_path);
    }

    public function existsOnDisk(): bool
    {
        return $this->file_path && Storage::disk('local')->exists($this->file_path);
    }

    public function humanReadableSize(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }

    public function usageCount(): int
    {
        return $this->perjalananDinas()->count();
    }

    public function thumbnailDiskPath(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return Storage::disk('local')->path($this->thumbnail_path);
    }

    public function existsThumbnail(): bool
    {
        return $this->thumbnail_path && Storage::disk('local')->exists($this->thumbnail_path);
    }

    public function deleteThumbnailFromDisk(): void
    {
        if ($this->thumbnail_path) {
            $path = $this->thumbnailDiskPath();
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
