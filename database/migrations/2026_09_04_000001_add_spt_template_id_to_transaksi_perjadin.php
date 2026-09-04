<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaksi_perjadin')) {
            return;
        }

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->integer('spt_template_id')->nullable()->after('akun_anggaran');

            $table->foreign('spt_template_id', 'transaksi_spt_template_foreign')
                ->references('id')->on('spt_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('transaksi_perjadin') && Schema::hasColumn('transaksi_perjadin', 'spt_template_id')) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->dropForeign('transaksi_spt_template_foreign');
                $table->dropColumn('spt_template_id');
            });
        }
    }
};
