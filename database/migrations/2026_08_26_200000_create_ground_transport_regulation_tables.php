<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ground_transport_regulations', function (Blueprint $table): void {
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

            $table->foreign('uploaded_by', 'ground_transport_regulation_uploader_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'ground_transport_regulation_activator_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['regulation_number', 'fiscal_year', 'revision'],
                'ground_transport_regulation_version_unique'
            );
            $table->index(['fiscal_year', 'status'], 'ground_transport_regulation_year_status_index');
            $table->index('csv_sha256', 'ground_transport_regulation_checksum_index');
        });

        Schema::create('ground_transport_rates', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->integer('ground_transport_regulation_id');
            $table->integer('province_id');
            $table->string('capital_city', 80);
            $table->string('destination_city', 100);
            $table->string('capital_key', 100);
            $table->string('destination_key', 120);
            $table->decimal('one_way_amount', 15, 2);
            $table->timestamps();

            $table->foreign('ground_transport_regulation_id', 'ground_transport_rate_regulation_foreign')
                ->references('id')->on('ground_transport_regulations')->cascadeOnDelete();
            $table->foreign('province_id', 'ground_transport_rate_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->unique(
                ['ground_transport_regulation_id', 'province_id', 'capital_key', 'destination_key'],
                'ground_transport_rate_route_unique'
            );
            $table->index(
                ['ground_transport_regulation_id', 'capital_key'],
                'ground_transport_rate_capital_index'
            );
            $table->index(
                ['ground_transport_regulation_id', 'destination_key'],
                'ground_transport_rate_destination_index'
            );
        });

        Schema::create('ground_transport_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('validated');
            $table->string('original_filename');
            $table->string('temporary_path')->nullable();
            $table->char('csv_sha256', 64);
            $table->json('parsed_rows');
            $table->integer('uploaded_by');
            $table->integer('ground_transport_regulation_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('uploaded_by', 'ground_transport_import_uploader_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('ground_transport_regulation_id', 'ground_transport_import_regulation_foreign')
                ->references('id')->on('ground_transport_regulations')->nullOnDelete();
            $table->index(['status', 'expires_at'], 'ground_transport_import_status_expiry_index');
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->string('ground_transport_source', 20)->nullable()->after('province_id');
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->string('transport_rate_source', 20)->nullable()->after('batas_transport_snapshot');
            $table->integer('ground_transport_regulation_id')->nullable()->after('transport_rate_source');
            $table->integer('ground_transport_province_id')->nullable()->after('ground_transport_regulation_id');
            $table->string('ground_transport_origin', 100)->nullable()->after('ground_transport_province_id');
            $table->string('ground_transport_destination', 100)->nullable()->after('ground_transport_origin');
            $table->decimal('ground_transport_one_way_snapshot', 15, 2)->nullable()
                ->after('ground_transport_destination');

            $table->foreign('ground_transport_regulation_id', 'transaksi_ground_transport_regulation_foreign')
                ->references('id')->on('ground_transport_regulations')->restrictOnDelete();
            $table->foreign('ground_transport_province_id', 'transaksi_ground_transport_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->index('ground_transport_regulation_id', 'transaksi_ground_transport_regulation_index');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_ground_transport_regulation_foreign');
            $table->dropForeign('transaksi_ground_transport_province_foreign');
            $table->dropIndex('transaksi_ground_transport_regulation_index');
            $table->dropColumn([
                'transport_rate_source',
                'ground_transport_regulation_id',
                'ground_transport_province_id',
                'ground_transport_origin',
                'ground_transport_destination',
                'ground_transport_one_way_snapshot',
            ]);
        });

        Schema::table('master_tarif', function (Blueprint $table): void {
            $table->dropColumn('ground_transport_source');
        });

        Schema::dropIfExists('ground_transport_imports');
        Schema::dropIfExists('ground_transport_rates');
        Schema::dropIfExists('ground_transport_regulations');
    }
};
