<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_regulations', function (Blueprint $table): void {
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

            $table->foreign('uploaded_by', 'hotel_regulation_uploader_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'hotel_regulation_activator_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['regulation_number', 'fiscal_year', 'revision'],
                'hotel_regulation_version_unique'
            );
            $table->index(['fiscal_year', 'status'], 'hotel_regulation_year_status_index');
            $table->index('csv_sha256', 'hotel_regulation_checksum_index');
        });

        Schema::create('hotel_rates', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->integer('hotel_regulation_id');
            $table->integer('province_id');
            $table->string('rate_group', 60);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->foreign('hotel_regulation_id', 'hotel_rate_regulation_foreign')
                ->references('id')->on('hotel_regulations')->cascadeOnDelete();
            $table->foreign('province_id', 'hotel_rate_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->unique(
                ['hotel_regulation_id', 'province_id', 'rate_group'],
                'hotel_rate_regulation_province_group_unique'
            );
        });

        Schema::create('hotel_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('validated');
            $table->string('original_filename');
            $table->string('temporary_path')->nullable();
            $table->char('csv_sha256', 64);
            $table->json('parsed_rows');
            $table->integer('uploaded_by');
            $table->integer('hotel_regulation_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('uploaded_by', 'hotel_import_uploader_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('hotel_regulation_id', 'hotel_import_regulation_foreign')
                ->references('id')->on('hotel_regulations')->nullOnDelete();
            $table->index(['status', 'expires_at'], 'hotel_import_status_expiry_index');
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->string('hotel_rate_source', 20)->nullable()->after('batas_hotel_per_hari_snapshot');
            $table->integer('hotel_regulation_id')->nullable()->after('hotel_rate_source');
            $table->integer('hotel_province_id')->nullable()->after('hotel_regulation_id');
            $table->string('hotel_rate_group', 60)->nullable()->after('hotel_province_id');
            $table->unsignedSmallInteger('hotel_nights_snapshot')->nullable()->after('hotel_rate_group');

            $table->foreign('hotel_regulation_id', 'transaksi_hotel_regulation_foreign')
                ->references('id')->on('hotel_regulations')->restrictOnDelete();
            $table->foreign('hotel_province_id', 'transaksi_hotel_province_foreign')
                ->references('id')->on('provinces')->restrictOnDelete();
            $table->index('hotel_regulation_id', 'transaksi_hotel_regulation_index');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_hotel_regulation_foreign');
            $table->dropForeign('transaksi_hotel_province_foreign');
            $table->dropIndex('transaksi_hotel_regulation_index');
            $table->dropColumn([
                'hotel_rate_source',
                'hotel_regulation_id',
                'hotel_province_id',
                'hotel_rate_group',
                'hotel_nights_snapshot',
            ]);
        });

        Schema::dropIfExists('hotel_imports');
        Schema::dropIfExists('hotel_rates');
        Schema::dropIfExists('hotel_regulations');
    }
};
