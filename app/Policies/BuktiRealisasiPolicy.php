<?php

namespace App\Policies;

use App\Models\BuktiRealisasi;
use App\Models\User;

class BuktiRealisasiPolicy
{
    public function view(User $user, BuktiRealisasi $evidence): bool
    {
        if (in_array($user->role, [User::ROLE_ADMIN, User::ROLE_VERIFIER], true)) {
            return true;
        }

        return $user->role === User::ROLE_USER
            && (int) $evidence->perjalananDinas?->user_id === (int) $user->id;
    }
}
