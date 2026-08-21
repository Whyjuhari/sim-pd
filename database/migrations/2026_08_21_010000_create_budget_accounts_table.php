<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_accounts', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('code', 80)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $codes = DB::table('transaksi_perjadin')
            ->whereNotNull('akun_anggaran')
            ->where('akun_anggaran', '<>', '')
            ->distinct()
            ->pluck('akun_anggaran')
            ->push('4053.PDI.002.054.B.524111')
            ->unique();

        foreach ($codes as $code) {
            DB::table('budget_accounts')->insert([
                'code' => $code,
                'description' => 'Akun anggaran perjalanan dinas',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_accounts');
    }
};
