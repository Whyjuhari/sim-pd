<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerjalananDinas extends Model
{
    public const SPT_GROUP_FIELDS = [
        'no_spt',
        'menimbang',
        'no_memo',
        'perihal_memo',
        'tgl_memo',
        'maksud_perjalanan',
        'kota_tujuan',
        'tempat_berangkat',
        'tgl_berangkat',
        'tgl_kembali',
        'lama_hari',
        'angkutan',
        'akun_anggaran',
    ];

    public const STATUS_DRAFT = 'draft_spt';
    public const STATUS_READY = 'siap_jalan';
    public const STATUS_PENDING = 'pending_verif';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public $timestamps = false;

    protected $table = 'transaksi_perjadin';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tgl_memo' => 'date',
            'tgl_berangkat' => 'date',
            'tgl_kembali' => 'date',
            'tgl_lapor' => 'date',
            'created_at' => 'datetime',
            'lama_hari' => 'integer',
            'estimasi_biaya' => 'decimal:2',
            'biaya_hotel_real' => 'decimal:2',
            'biaya_tiket_real' => 'decimal:2',
            'biaya_hotel_approved' => 'decimal:2',
            'biaya_tiket_approved' => 'decimal:2',
            'uang_harian_per_hari_snapshot' => 'decimal:2',
            'batas_hotel_per_hari_snapshot' => 'decimal:2',
            'batas_transport_snapshot' => 'decimal:2',
            'total_cair' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function laporan(): HasOne
    {
        return $this->hasOne(
            LaporanPerjadin::class,
            'perjalanan_dinas_id'
        );
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(PerjalananDinasStatusHistory::class, 'perjalanan_dinas_id')
            ->latest('created_at');
    }
    public function sptGroupingKey(): string
    {
        if ($this->spt_group_id) {
            return 'group:' . $this->spt_group_id;
        }

        $original = $this->getRawOriginal();
        $values = array_map(fn(string $field): mixed => $original[$field] ?? null, self::SPT_GROUP_FIELDS);

        return 'legacy:' . hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
