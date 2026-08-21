<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const GROUP_FIELDS = [
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

    public function up(): void
    {
        if (! Schema::hasTable('transaksi_perjadin')) {
            return;
        }

        if (! Schema::hasColumn('transaksi_perjadin', 'spt_group_id')) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->uuid('spt_group_id')->nullable()->after('id')->index();
            });
        }

        $rows = DB::table('transaksi_perjadin')
            ->whereNull('spt_group_id')
            ->orderBy('id')
            ->get(['id', ...self::GROUP_FIELDS]);

        $rows
            ->groupBy(fn (object $row): string => hash('sha256', json_encode(
                array_map(fn (string $field): mixed => $row->{$field}, self::GROUP_FIELDS),
                JSON_THROW_ON_ERROR,
            )))
            ->each(function ($group): void {
                DB::table('transaksi_perjadin')
                    ->whereIn('id', $group->pluck('id'))
                    ->update(['spt_group_id' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('transaksi_perjadin') && Schema::hasColumn('transaksi_perjadin', 'spt_group_id')) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->dropColumn('spt_group_id');
            });
        }
    }
};
