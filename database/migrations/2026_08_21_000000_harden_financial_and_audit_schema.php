<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['users', 'master_tarif', 'settings', 'transaksi_perjadin'] as $table) {
                DB::statement(
                    "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
            }
        }

        DB::table('users')
            ->where(function ($query): void {
                $query->where('nip', '-')->orWhere('nip', '');
            })
            ->update(['nip' => null]);

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_password_change')->default(false)->after('password');
            $table->unique('nip', 'users_nip_unique');
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->decimal('biaya_tiket_approved', 15, 2)->nullable()->after('biaya_hotel_approved');
            $table->decimal('uang_harian_per_hari_snapshot', 15, 2)->nullable()->after('estimasi_biaya');
            $table->decimal('batas_hotel_per_hari_snapshot', 15, 2)->nullable()->after('uang_harian_per_hari_snapshot');
            $table->decimal('batas_transport_snapshot', 15, 2)->nullable()->after('batas_hotel_per_hari_snapshot');
            $table->integer('created_by')->nullable()->after('user_id');
            $table->integer('verified_by')->nullable()->after('catatan_verifikator');
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            $table->foreign('created_by', 'transaksi_created_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('verified_by', 'transaksi_verified_by_foreign')
                ->references('id')->on('users')->nullOnDelete();

            $table->unique(['spt_group_id', 'user_id'], 'transaksi_group_user_unique');
            $table->index('status', 'transaksi_status_index');
            $table->index('tgl_berangkat', 'transaksi_tgl_berangkat_index');
            $table->index(['user_id', 'status'], 'transaksi_user_status_index');
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->unique('kota_tujuan', 'master_tarif_kota_unique');
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->unique('nama_setting', 'settings_nama_unique');
        });

        $settings = DB::table('settings')
            ->whereIn('nama_setting', ['batas_hotel', 'pagu_tiket', 'batas_transport_darat'])
            ->pluck('nilai_setting', 'nama_setting');

        DB::table('transaksi_perjadin')
            ->orderBy('id')
            ->chunkById(100, function ($travels) use ($settings): void {
                foreach ($travels as $travel) {
                    $dailyRate = DB::table('master_tarif')
                        ->where('kota_tujuan', $travel->kota_tujuan)
                        ->value('uang_saku_per_hari');
                    $transportLimit = $travel->angkutan === 'Pesawat Udara'
                        ? ($settings['pagu_tiket'] ?? 0)
                        : ($settings['batas_transport_darat'] ?? 0);

                    DB::table('transaksi_perjadin')
                        ->where('id', $travel->id)
                        ->update([
                            'biaya_tiket_approved' => $travel->status === 'approved'
                                ? $travel->biaya_tiket_real
                                : null,
                            'uang_harian_per_hari_snapshot' => $dailyRate,
                            'batas_hotel_per_hari_snapshot' => $settings['batas_hotel'] ?? 0,
                            'batas_transport_snapshot' => $transportLimit,
                        ]);
                }
            }, 'id');

        Schema::create('perjalanan_dinas_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->integer('perjalanan_dinas_id');
            $table->integer('actor_id')->nullable();
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('perjalanan_dinas_id', 'status_history_travel_foreign')
                ->references('id')->on('transaksi_perjadin')->cascadeOnDelete();
            $table->foreign('actor_id', 'status_history_actor_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['perjalanan_dinas_id', 'created_at'], 'status_history_travel_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perjalanan_dinas_status_histories');

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique('settings_nama_unique');
        });
        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->dropUnique('master_tarif_kota_unique');
        });
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_created_by_foreign');
            $table->dropForeign('transaksi_verified_by_foreign');
            $table->dropUnique('transaksi_group_user_unique');
            $table->dropIndex('transaksi_status_index');
            $table->dropIndex('transaksi_tgl_berangkat_index');
            $table->dropIndex('transaksi_user_status_index');
            $table->dropColumn([
                'biaya_tiket_approved',
                'uang_harian_per_hari_snapshot',
                'batas_hotel_per_hari_snapshot',
                'batas_transport_snapshot',
                'created_by',
                'verified_by',
                'verified_at',
            ]);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_nip_unique');
            $table->dropColumn('force_password_change');
        });
    }
};
