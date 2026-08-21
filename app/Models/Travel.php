<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Travel extends Model
{

    protected $table = 'travels';

    protected $fillable = [
        'no_spt',
        'menimbang',
        'no_memo',
        'perihal_memo',
        'tgl_memo',
        'maksud_perjalanan',
        'tgl_berangkat',
        'tgl_kembali',
        'lama_hari',
        'akun_anggaran',
        'estimasi_biaya',
        'status',
    ];

    // public function pegawai()
    // {
    //     return $this->belongsToMany(Employee::class, 'travel_employee', 'travel_id', 'employee_id');
    // }
}