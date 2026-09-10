<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DipaSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'document_date' => 'date',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
