<?php

namespace App\Support;

use App\Models\PerjalananDinas;

class EmployeeTravelStage
{
    /** @return array{label: string, status_key: string, step: int, step_label: string} */
    public static function for(PerjalananDinas $travel): array
    {
        if ($travel->status === PerjalananDinas::STATUS_APPROVED) {
            return self::stage('Selesai', 'approved', 5, 'Selesai');
        }

        if ($travel->status === PerjalananDinas::STATUS_REJECTED) {
            return self::stage('Perlu Revisi', 'rejected', 4, 'Perbaiki realisasi');
        }

        if ($travel->status === PerjalananDinas::STATUS_PENDING) {
            return self::stage('Menunggu Verifikasi', 'pending_verif', 4, 'Menunggu verifikasi');
        }

        if ($travel->laporan) {
            return self::stage('Realisasi Belum Dikirim', 'siap_jalan', 3, 'Kirim realisasi');
        }

        if ($travel->status === PerjalananDinas::STATUS_DRAFT) {
            return self::stage('Draft SPT', 'draft_spt', 1, 'SPT belum diterbitkan');
        }

        $today = today();
        if ($travel->tgl_berangkat && $today->lt($travel->tgl_berangkat->copy()->startOfDay())) {
            return self::stage('Terjadwal', 'scheduled', 2, 'Isi laporan');
        }
        if (
            $travel->tgl_berangkat && $travel->tgl_kembali
            && $today->betweenIncluded(
                $travel->tgl_berangkat->copy()->startOfDay(),
                $travel->tgl_kembali->copy()->endOfDay()
            )
        ) {
            return self::stage('Sedang Berlangsung', 'ongoing', 2, 'Isi laporan');
        }

        return self::stage('Siap Dilaporkan', 'ready_report', 2, 'Isi laporan');
    }

    /** @return array{label: string, status_key: string, step: int, step_label: string} */
    private static function stage(string $label, string $statusKey, int $step, string $stepLabel): array
    {
        return [
            'label' => $label,
            'status_key' => $statusKey,
            'step' => $step,
            'step_label' => $stepLabel,
        ];
    }
}
