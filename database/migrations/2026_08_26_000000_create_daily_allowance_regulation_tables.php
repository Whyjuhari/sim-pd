<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->char('code', 2)->unique();
            $table->string('name', 80)->unique();
        });

        DB::table('provinces')->insert($this->provinces());

        Schema::create('daily_allowance_regulations', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('regulation_number', 100);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('source_reference', 255);
            $table->string('status', 20)->default('draft');
            $table->string('original_filename');
            $table->string('csv_path');
            $table->char('csv_sha256', 64);
            $table->integer('uploaded_by');
            $table->integer('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by', 'daily_allowance_regulation_uploader_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'daily_allowance_regulation_activator_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['regulation_number', 'fiscal_year', 'revision'],
                'daily_allowance_regulation_version_unique'
            );
            $table->index(['fiscal_year', 'status'], 'daily_allowance_regulation_year_status_index');
            $table->index('csv_sha256', 'daily_allowance_regulation_checksum_index');
        });

        Schema::create('daily_allowance_rates', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->integer('daily_allowance_regulation_id');
            $table->integer('province_id');
            $table->decimal('outside_city', 15, 2);
            $table->decimal('inside_city_over_8_hours', 15, 2);
            $table->decimal('training', 15, 2);
            $table->timestamps();

            $table->foreign('daily_allowance_regulation_id', 'daily_allowance_rate_regulation_foreign')
                ->references('id')->on('daily_allowance_regulations')->cascadeOnDelete();
            $table->foreign('province_id', 'daily_allowance_rate_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->unique(
                ['daily_allowance_regulation_id', 'province_id'],
                'daily_allowance_rate_regulation_province_unique'
            );
        });

        Schema::create('daily_allowance_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('validated');
            $table->string('original_filename');
            $table->string('temporary_path')->nullable();
            $table->char('csv_sha256', 64);
            $table->json('parsed_rows');
            $table->integer('uploaded_by');
            $table->integer('daily_allowance_regulation_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('uploaded_by', 'daily_allowance_import_uploader_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('daily_allowance_regulation_id', 'daily_allowance_import_regulation_foreign')
                ->references('id')->on('daily_allowance_regulations')->nullOnDelete();
            $table->index(['status', 'expires_at'], 'daily_allowance_import_status_expiry_index');
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->integer('province_id')->nullable()->after('kota_tujuan');
            $table->foreign('province_id', 'master_tarif_province_foreign')
                ->references('id')->on('provinces')->nullOnDelete();
            $table->index('province_id', 'master_tarif_province_index');
        });

        $cityMappings = [
            'Jakarta' => '31',
            'Bandung' => '32',
            'Semarang' => '33',
            'Yogyakarta' => '34',
            'Surabaya' => '35',
            'Denpasar' => '51',
            'Makassar' => '73',
            'Kendari' => '74',
        ];

        foreach ($cityMappings as $city => $provinceCode) {
            DB::table('master_tarif')
                ->where('kota_tujuan', $city)
                ->update([
                    'province_id' => DB::table('provinces')->where('code', $provinceCode)->value('id'),
                ]);
        }

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->string('daily_allowance_source', 20)->nullable()->after('uang_harian_per_hari_snapshot');
            $table->string('daily_allowance_category', 40)->nullable()->after('daily_allowance_source');
            $table->integer('daily_allowance_regulation_id')->nullable()->after('daily_allowance_category');
            $table->integer('daily_allowance_province_id')->nullable()->after('daily_allowance_regulation_id');

            $table->foreign('daily_allowance_regulation_id', 'transaksi_daily_allowance_regulation_foreign')
                ->references('id')->on('daily_allowance_regulations')->restrictOnDelete();
            $table->foreign('daily_allowance_province_id', 'transaksi_daily_allowance_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->index('daily_allowance_regulation_id', 'transaksi_daily_allowance_regulation_index');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_daily_allowance_regulation_foreign');
            $table->dropForeign('transaksi_daily_allowance_province_foreign');
            $table->dropIndex('transaksi_daily_allowance_regulation_index');
            $table->dropColumn([
                'daily_allowance_source',
                'daily_allowance_category',
                'daily_allowance_regulation_id',
                'daily_allowance_province_id',
            ]);
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->dropForeign('master_tarif_province_foreign');
            $table->dropIndex('master_tarif_province_index');
            $table->dropColumn('province_id');
        });

        Schema::dropIfExists('daily_allowance_imports');
        Schema::dropIfExists('daily_allowance_rates');
        Schema::dropIfExists('daily_allowance_regulations');
        Schema::dropIfExists('provinces');
    }

    /** @return array<int, array{code: string, name: string}> */
    private function provinces(): array
    {
        return [
            ['code' => '11', 'name' => 'ACEH'],
            ['code' => '12', 'name' => 'SUMATRA UTARA'],
            ['code' => '13', 'name' => 'SUMATRA BARAT'],
            ['code' => '14', 'name' => 'RIAU'],
            ['code' => '15', 'name' => 'JAMBI'],
            ['code' => '16', 'name' => 'SUMATRA SELATAN'],
            ['code' => '17', 'name' => 'BENGKULU'],
            ['code' => '18', 'name' => 'LAMPUNG'],
            ['code' => '19', 'name' => 'KEPULAUAN BANGKA BELITUNG'],
            ['code' => '21', 'name' => 'KEPULAUAN RIAU'],
            ['code' => '31', 'name' => 'DKI JAKARTA'],
            ['code' => '32', 'name' => 'JAWA BARAT'],
            ['code' => '33', 'name' => 'JAWA TENGAH'],
            ['code' => '34', 'name' => 'DI YOGYAKARTA'],
            ['code' => '35', 'name' => 'JAWA TIMUR'],
            ['code' => '36', 'name' => 'BANTEN'],
            ['code' => '51', 'name' => 'BALI'],
            ['code' => '52', 'name' => 'NUSA TENGGARA BARAT'],
            ['code' => '53', 'name' => 'NUSA TENGGARA TIMUR'],
            ['code' => '61', 'name' => 'KALIMANTAN BARAT'],
            ['code' => '62', 'name' => 'KALIMANTAN TENGAH'],
            ['code' => '63', 'name' => 'KALIMANTAN SELATAN'],
            ['code' => '64', 'name' => 'KALIMANTAN TIMUR'],
            ['code' => '65', 'name' => 'KALIMANTAN UTARA'],
            ['code' => '71', 'name' => 'SULAWESI UTARA'],
            ['code' => '72', 'name' => 'SULAWESI TENGAH'],
            ['code' => '73', 'name' => 'SULAWESI SELATAN'],
            ['code' => '74', 'name' => 'SULAWESI TENGGARA'],
            ['code' => '75', 'name' => 'GORONTALO'],
            ['code' => '76', 'name' => 'SULAWESI BARAT'],
            ['code' => '81', 'name' => 'MALUKU'],
            ['code' => '82', 'name' => 'MALUKU UTARA'],
            ['code' => '91', 'name' => 'PAPUA BARAT'],
            ['code' => '92', 'name' => 'PAPUA BARAT DAYA'],
            ['code' => '94', 'name' => 'PAPUA'],
            ['code' => '95', 'name' => 'PAPUA SELATAN'],
            ['code' => '96', 'name' => 'PAPUA TENGAH'],
            ['code' => '97', 'name' => 'PAPUA PEGUNUNGAN'],
        ];
    }
};
