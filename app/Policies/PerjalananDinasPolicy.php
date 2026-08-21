<?php

namespace App\Policies;

use App\Models\PerjalananDinas;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PerjalananDinasPolicy
{
    public function printTravelDocument(User $user, PerjalananDinas $travel): Response
    {
        $canAccessTravel =
            $user->hasRole([
                User::ROLE_ADMIN,
                User::ROLE_VERIFIER,
            ])
            ||
            (
                $user->hasRole(User::ROLE_USER)
                && (int) $travel->user_id === (int) $user->id
            );

        if (! $canAccessTravel) {

            if ($user->hasRole(User::ROLE_USER)) {
                return Response::denyAsNotFound();
            }

            return Response::deny(
                'Anda tidak memiliki akses untuk mencetak dokumen perjalanan ini.'
            );
        }

        if (
            $travel->status
            !== PerjalananDinas::STATUS_APPROVED
        ) {
            return Response::deny(
                'Dokumen perjalanan final hanya dapat dicetak setelah transaksi disetujui verifikator.'
            );
        }

        return Response::allow();
    }


    public function printSuratTugas(
        User $user,
        PerjalananDinas $travel
    ): Response {
        /*
     * ============================================
     * OFFICER
     * ============================================
     *
     * Officer adalah pembuat/pengelola Surat Tugas.
     * Jadi Officer boleh mencetak SPT.
     */
        if (
            $user->hasRole(
                User::ROLE_OFFICER
            )
        ) {
            return Response::allow();
        }


        /*
     * ============================================
     * SELAIN PEGAWAI
     * ============================================
     *
     * Untuk saat ini akses hanya diberikan kepada:
     *
     * - Officer
     * - Pegawai yang tergabung dalam SPT
     */
        if (
            ! $user->hasRole(
                User::ROLE_USER
            )
        ) {
            return Response::deny(
                'Anda tidak memiliki akses untuk mencetak Surat Tugas ini.'
            );
        }


        if (
            (int) $travel->user_id
            ===
            (int) $user->id
        ) {
            return Response::allow();
        }
        if ($travel->spt_group_id) {
            $isGroupMember =
                PerjalananDinas::query()
                ->where(
                    'spt_group_id',
                    $travel->spt_group_id
                )
                ->where(
                    'user_id',
                    $user->id
                )
                ->exists();

            if ($isGroupMember) {
                return Response::allow();
            }
        }

        return Response::denyAsNotFound();
    }
}
