<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                // Database warisan menggunakan INT signed. Pertahankan tipe ini
                // agar foreign key laporan kompatibel pada instalasi MySQL baru.
                $table->integer('id')->autoIncrement();
                $table->string('username', 50)->unique();
                $table->string('password');
                $table->string('nama_lengkap', 100);
                $table->string('nip', 30)->nullable();
                $table->string('jabatan', 50)->nullable();
                $table->enum('role', ['officer', 'program', 'user', 'verifikator', 'head', 'admin']);
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->string('pangkat_golongan', 50)->nullable();
                $table->string('foto')->nullable()->default('default.png');
            });
        }

        if (! Schema::hasTable('master_tarif')) {
            Schema::create('master_tarif', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('kota_tujuan', 50);
                $table->decimal('uang_saku_per_hari', 10, 2);
                $table->timestamp('updated_at')->nullable()->useCurrent();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('nama_setting', 50)->nullable();
                $table->decimal('nilai_setting', 15, 2)->nullable();
            });
        }

        if (! Schema::hasTable('transaksi_perjadin')) {
            Schema::create('transaksi_perjadin', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('no_spt', 50);
                $table->string('menimbang');
                $table->string('no_memo');
                $table->string('perihal_memo');
                $table->date('tgl_memo')->nullable();
                $table->integer('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->string('kota_tujuan', 50);
                $table->date('tgl_berangkat');
                $table->date('tgl_kembali');
                $table->integer('lama_hari');
                $table->decimal('estimasi_biaya', 15, 2)->default(0);
                $table->decimal('biaya_hotel_real', 15, 2)->default(0);
                $table->decimal('biaya_tiket_real', 15, 2)->default(0);
                $table->string('foto_bukti')->nullable();
                $table->date('tgl_lapor')->nullable();
                $table->decimal('biaya_hotel_approved', 15, 2)->default(0);
                $table->decimal('total_cair', 15, 2)->default(0);
                $table->text('catatan_verifikator')->nullable();
                $table->enum('status', ['draft_spt', 'siap_jalan', 'pending_verif', 'approved', 'rejected'])->default('draft_spt');
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->text('maksud_perjalanan')->nullable();
                $table->string('angkutan', 50)->default('Pesawat Udara');
                $table->string('tempat_berangkat', 50)->default('Pangkep');
                $table->string('akun_anggaran', 50)->default('4053.PDI.002.054.B.524111');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_perjadin');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('master_tarif');
        Schema::dropIfExists('users');
    }
};
