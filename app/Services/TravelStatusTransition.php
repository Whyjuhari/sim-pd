<?php

namespace App\Services;

use App\Models\PerjalananDinas;
use App\Models\PerjalananDinasStatusHistory;
use App\Models\User;
use DomainException;

class TravelStatusTransition
{
    private const ALLOWED = [
        PerjalananDinas::STATUS_READY => [PerjalananDinas::STATUS_PENDING],
        PerjalananDinas::STATUS_PENDING => [
            PerjalananDinas::STATUS_APPROVED,
            PerjalananDinas::STATUS_REJECTED,
        ],
        PerjalananDinas::STATUS_REJECTED => [PerjalananDinas::STATUS_PENDING],
    ];

    public function apply(
        PerjalananDinas $travel,
        string $toStatus,
        ?User $actor,
        array $changes = [],
        ?string $note = null
    ): void {
        $fromStatus = (string) $travel->status;

        if (! in_array($toStatus, self::ALLOWED[$fromStatus] ?? [], true)) {
            throw new DomainException("Transisi status {$fromStatus} ke {$toStatus} tidak diizinkan.");
        }

        $travel->update([
            ...$changes,
            'status' => $toStatus,
        ]);

        PerjalananDinasStatusHistory::query()->create([
            'perjalanan_dinas_id' => $travel->id,
            'actor_id' => $actor?->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
        ]);
    }
}
