<?php

namespace App\Services;

use App\Models\PerjalananDinas;
use App\Models\SptSrikandiWorkflow;
use App\Models\User;

class NavigationWorkCount
{
    public function for(User $user): int
    {
        return match ($user->role) {
            User::ROLE_USER => PerjalananDinas::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    PerjalananDinas::STATUS_READY,
                    PerjalananDinas::STATUS_REJECTED,
                ])->count(),
            User::ROLE_VERIFIER => PerjalananDinas::query()
                ->where('status', PerjalananDinas::STATUS_PENDING)
                ->count(),
            User::ROLE_OFFICER => SptSrikandiWorkflow::query()
                ->whereIn('status', SptSrikandiWorkflow::ACTIONABLE_STATUSES)->count(),
            default => 0,
        };
    }
}
