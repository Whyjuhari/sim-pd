<?php

namespace App\Repositories;

use App\Models\MasterTarif;
use App\Models\PerjalananDinas;

class PerjalananDinasRepository
{
    public function findForDocument(int $id): ?array
    {
        $travel = PerjalananDinas::query()->with('pegawai')->find($id);

        if (! $travel || ! $travel->pegawai) {
            return null;
        }

        $dailyRate = (float) ($travel->uang_harian_per_hari_snapshot
            ?? MasterTarif::query()
                ->where('kota_tujuan', $travel->kota_tujuan)
                ->value('uang_saku_per_hari')
            ?? 0);
        $dailyTotal = $dailyRate * $travel->lama_hari;
        $hotelApproved = (float) $travel->biaya_hotel_approved;
        $ticketApproved = (float) ($travel->biaya_tiket_approved ?? $travel->biaya_tiket_real);
        $total = (float) $travel->total_cair;

        if ($total <= 0) {
            $total = $dailyTotal + $hotelApproved + $ticketApproved;
        }

        return [
            ...$travel->getRawOriginal(),
            'nama_lengkap' => $travel->pegawai->nama_lengkap,
            'nip' => $travel->pegawai->nip,
            'jabatan' => $travel->pegawai->jabatan,
            'pangkat' => $travel->pegawai->pangkat_golongan,
            'uang_harian_per_hari' => $dailyRate,
            'total_uang_harian' => $dailyTotal,
            'biaya_hotel_dokumen' => $hotelApproved,
            'biaya_tiket_dokumen' => $ticketApproved,
            'transport_bandara_dokumen' => 0.0,
            'total_lumpsum' => $total,
        ];
    }

    public function findSuratTugasGroup(int $id): ?array
    {
        $reference = PerjalananDinas::query()->with('pegawai')->find($id);

        if (! $reference || ! $reference->pegawai) {
            return null;
        }

        $query = PerjalananDinas::query()->with('pegawai');

        if ($reference->spt_group_id) {
            $query->where('spt_group_id', $reference->spt_group_id);
        } else {
            foreach (PerjalananDinas::SPT_GROUP_FIELDS as $field) {
                $query->where($field, $reference->getRawOriginal($field));
            }
        }

        $travels = $query->orderBy('id')->get()->filter(fn (PerjalananDinas $travel): bool => $travel->pegawai !== null);

        if ($travels->isEmpty()) {
            return null;
        }

        return [
            ...$this->findForDocument($reference->id),
            'pegawai_list' => $travels->map(fn (PerjalananDinas $travel): array => [
                'id' => $travel->pegawai->id,
                'nama_lengkap' => $travel->pegawai->nama_lengkap,
                'nip' => $travel->pegawai->nip,
                'pangkat_golongan' => $travel->pegawai->pangkat_golongan,
                'jabatan' => $travel->pegawai->jabatan,
            ])->values()->all(),
        ];
    }
}
