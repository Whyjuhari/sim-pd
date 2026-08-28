<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroundTransportImport extends Model
{
    use HasUuids;

    public const STATUS_VALIDATED = 'validated';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'parsed_rows' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function regulation(): BelongsTo
    {
        return $this->belongsTo(GroundTransportRegulation::class, 'ground_transport_regulation_id');
    }
}
