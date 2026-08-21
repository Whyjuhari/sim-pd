<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(
            'laporan_perjadin',
            function (Blueprint $table): void {
                $table->id();
                $table
                    ->integer('perjalanan_dinas_id')
                    ->unique();

                $table
                    ->foreign('perjalanan_dinas_id')
                    ->references('id')
                    ->on('transaksi_perjadin')
                    ->cascadeOnDelete();
                $table
                    ->longText('hasil_pelaksanaan');
                $table
                    ->longText('kesimpulan');
                $table
                    ->date('tanggal_laporan');
                $table->timestamps();
            }
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laporan_perjadin');
    }
};
