<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('air_transport_regulations', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('regulation_number', 100);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('source_reference', 255);
            $table->string('status', 20)->default('draft');
            $table->string('terminal_original_filename');
            $table->string('terminal_csv_path');
            $table->char('terminal_csv_sha256', 64);
            $table->string('airfare_original_filename');
            $table->string('airfare_csv_path');
            $table->char('airfare_csv_sha256', 64);
            $table->integer('uploaded_by');
            $table->integer('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by', 'air_transport_regulation_uploader_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'air_transport_regulation_activator_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['regulation_number', 'fiscal_year', 'revision'],
                'air_transport_regulation_version_unique'
            );
            $table->index(['fiscal_year', 'status'], 'air_transport_regulation_year_status_index');
        });

        Schema::create('terminal_transport_rates', function (Blueprint $table): void {
            $table->id();
            $table->integer('air_transport_regulation_id');
            $table->integer('province_id');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->foreign('air_transport_regulation_id', 'terminal_transport_rate_regulation_foreign')
                ->references('id')->on('air_transport_regulations')->cascadeOnDelete();
            $table->foreign('province_id', 'terminal_transport_rate_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->unique(
                ['air_transport_regulation_id', 'province_id'],
                'terminal_transport_rate_regulation_province_unique'
            );
        });

        Schema::create('domestic_airfare_rates', function (Blueprint $table): void {
            $table->id();
            $table->integer('air_transport_regulation_id');
            $table->unsignedSmallInteger('source_number');
            $table->string('origin_city', 80);
            $table->string('destination_city', 80);
            $table->string('origin_key', 100);
            $table->string('destination_key', 100);
            $table->decimal('business_amount', 15, 2);
            $table->decimal('economy_amount', 15, 2);
            $table->timestamps();

            $table->foreign('air_transport_regulation_id', 'domestic_airfare_rate_regulation_foreign')
                ->references('id')->on('air_transport_regulations')->cascadeOnDelete();
            $table->unique(
                ['air_transport_regulation_id', 'source_number'],
                'domestic_airfare_rate_regulation_number_unique'
            );
            $table->unique(
                ['air_transport_regulation_id', 'origin_key', 'destination_key'],
                'domestic_airfare_rate_directed_route_unique'
            );
            $table->index(
                ['air_transport_regulation_id', 'destination_key'],
                'domestic_airfare_rate_destination_index'
            );
        });

        Schema::create('air_transport_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('validated');
            $table->string('terminal_original_filename');
            $table->string('terminal_temporary_path')->nullable();
            $table->char('terminal_csv_sha256', 64);
            $table->json('terminal_rows');
            $table->string('airfare_original_filename');
            $table->string('airfare_temporary_path')->nullable();
            $table->char('airfare_csv_sha256', 64);
            $table->json('airfare_rows');
            $table->integer('uploaded_by');
            $table->integer('air_transport_regulation_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('uploaded_by', 'air_transport_import_uploader_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('air_transport_regulation_id', 'air_transport_import_regulation_foreign')
                ->references('id')->on('air_transport_regulations')->nullOnDelete();
            $table->index(['status', 'expires_at'], 'air_transport_import_status_expiry_index');
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->string('airfare_city', 80)->nullable()->after('ground_transport_source');
            $table->string('airfare_city_key', 100)->nullable()->after('airfare_city');
            $table->index('airfare_city_key', 'master_tarif_airfare_city_key_index');
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->integer('air_transport_regulation_id')->nullable()->after('ground_transport_one_way_snapshot');
            $table->integer('air_origin_province_id')->nullable()->after('air_transport_regulation_id');
            $table->integer('air_destination_province_id')->nullable()->after('air_origin_province_id');
            $table->string('air_origin_city', 80)->nullable()->after('air_destination_province_id');
            $table->string('air_destination_city', 80)->nullable()->after('air_origin_city');
            $table->string('airfare_class', 20)->nullable()->after('air_destination_city');
            $table->string('terminal_origin_source', 20)->nullable()->after('airfare_class');
            $table->decimal('terminal_origin_one_way_snapshot', 15, 2)->nullable()->after('terminal_origin_source');
            $table->string('terminal_destination_source', 20)->nullable()->after('terminal_origin_one_way_snapshot');
            $table->decimal('terminal_destination_one_way_snapshot', 15, 2)->nullable()->after('terminal_destination_source');
            $table->string('airfare_rate_source', 20)->nullable()->after('terminal_destination_one_way_snapshot');
            $table->decimal('airfare_business_pp_snapshot', 15, 2)->nullable()->after('airfare_rate_source');
            $table->decimal('airfare_economy_pp_snapshot', 15, 2)->nullable()->after('airfare_business_pp_snapshot');

            $table->foreign('air_transport_regulation_id', 'transaksi_air_transport_regulation_foreign')
                ->references('id')->on('air_transport_regulations')->restrictOnDelete();
            $table->foreign('air_origin_province_id', 'transaksi_air_origin_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->foreign('air_destination_province_id', 'transaksi_air_destination_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->index('air_transport_regulation_id', 'transaksi_air_transport_regulation_index');
        });

        Schema::table('realisasi_rincian', function (Blueprint $table): void {
            $table->string('benchmark_source', 30)->nullable()->after('nilai_disetujui');
            $table->decimal('benchmark_amount_snapshot', 15, 2)->nullable()->after('benchmark_source');
            $table->text('overrun_reason')->nullable()->after('benchmark_amount_snapshot');
            $table->boolean('office_route_confirmed')->default(false)->after('overrun_reason');
            $table->boolean('non_private_vehicle_confirmed')->default(false)->after('office_route_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('realisasi_rincian', function (Blueprint $table): void {
            $table->dropColumn([
                'benchmark_source', 'benchmark_amount_snapshot', 'overrun_reason',
                'office_route_confirmed', 'non_private_vehicle_confirmed',
            ]);
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_air_transport_regulation_foreign');
            $table->dropForeign('transaksi_air_origin_province_foreign');
            $table->dropForeign('transaksi_air_destination_province_foreign');
            $table->dropIndex('transaksi_air_transport_regulation_index');
            $table->dropColumn([
                'air_transport_regulation_id', 'air_origin_province_id', 'air_destination_province_id',
                'air_origin_city', 'air_destination_city', 'airfare_class', 'terminal_origin_source',
                'terminal_origin_one_way_snapshot', 'terminal_destination_source',
                'terminal_destination_one_way_snapshot', 'airfare_rate_source',
                'airfare_business_pp_snapshot', 'airfare_economy_pp_snapshot',
            ]);
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->dropIndex('master_tarif_airfare_city_key_index');
            $table->dropColumn(['airfare_city', 'airfare_city_key']);
        });

        Schema::dropIfExists('air_transport_imports');
        Schema::dropIfExists('domestic_airfare_rates');
        Schema::dropIfExists('terminal_transport_rates');
        Schema::dropIfExists('air_transport_regulations');
    }
};
