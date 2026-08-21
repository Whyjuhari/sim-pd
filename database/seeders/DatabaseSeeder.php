<?php

namespace Database\Seeders;

use App\Models\MasterTarif;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('123456'),
                'force_password_change' => true,
                'nama_lengkap' => 'Administrator SIM-PD',
                'role' => User::ROLE_ADMIN,
                'foto' => 'default.png',
            ],
        );

        foreach ([
            'batas_hotel' => 500000,
            'pagu_tiket' => 2000000,
            'batas_transport_darat' => 1000000,
        ] as $name => $value) {
            Setting::query()->firstOrCreate(['nama_setting' => $name], ['nilai_setting' => $value]);
        }

        MasterTarif::query()->firstOrCreate(
            ['kota_tujuan' => 'Makassar'],
            ['uang_saku_per_hari' => 370000],
        );
    }
}
