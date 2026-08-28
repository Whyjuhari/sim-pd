<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bukti_realisasi', function (Blueprint $table): void {
            $table->id();
            // transaksi_perjadin menggunakan INT signed pada database warisan.
            $table->integer('perjalanan_dinas_id');
            $table->enum('jenis', ['hotel', 'transportasi']);
            $table->string('path', 255);
            $table->string('nama_asli', 255);
            $table->string('mime_type', 50);
            $table->unsignedInteger('ukuran');
            $table->unsignedSmallInteger('urutan');
            $table->timestamps();

            $table->foreign('perjalanan_dinas_id', 'bukti_realisasi_travel_foreign')
                ->references('id')
                ->on('transaksi_perjadin')
                ->cascadeOnDelete();
            $table->index(
                ['perjalanan_dinas_id', 'jenis', 'urutan'],
                'bukti_realisasi_travel_type_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bukti_realisasi');
    }
};
