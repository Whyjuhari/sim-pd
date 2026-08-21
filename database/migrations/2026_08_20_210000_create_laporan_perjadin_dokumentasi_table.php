<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'laporan_perjadin_dokumentasi',
            function (Blueprint $table): void {
                $table->id();
                $table
                    ->foreignId('laporan_perjadin_id')
                    ->constrained('laporan_perjadin')
                    ->cascadeOnDelete();
                $table->string('path', 255);
                $table->unsignedTinyInteger('urutan');
                $table->timestamps();

                $table->unique(
                    ['laporan_perjadin_id', 'urutan'],
                    'laporan_dokumentasi_urutan_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'laporan_perjadin_dokumentasi'
        );
    }
};
