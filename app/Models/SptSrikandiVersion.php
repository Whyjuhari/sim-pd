<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SptSrikandiVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'docx_size_bytes' => 'integer',
            'prepared_at' => 'datetime',
            'submitted_at' => 'datetime',
            'revision_requested_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(SptSrikandiWorkflow::class, 'workflow_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function revisionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revision_requested_by');
    }
}
