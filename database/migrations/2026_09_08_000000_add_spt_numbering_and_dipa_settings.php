<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dipa_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->unique();
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by', 'dipa_settings_updated_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->string('no_memo')->nullable()->change();
            $table->string('perihal_memo')->nullable()->change();

            $table->string('spt_internal_reference', 40)->nullable()->after('spt_group_id');
            $table->string('spt_number_mode', 30)->default('manual')->after('spt_internal_reference');
            $table->string('spt_external_number', 50)->nullable()->after('spt_number_mode');
            $table->timestamp('spt_external_number_recorded_at')->nullable()->after('spt_external_number');
            $table->integer('spt_external_number_recorded_by')->nullable()->after('spt_external_number_recorded_at');
            $table->string('spt_template_variant', 50)->nullable()->after('spt_template_id');
            $table->unsignedBigInteger('dipa_setting_id')->nullable()->after('spt_template_variant');
            $table->unsignedSmallInteger('dipa_fiscal_year_snapshot')->nullable()->after('dipa_setting_id');
            $table->string('dipa_number_snapshot', 100)->nullable()->after('dipa_fiscal_year_snapshot');
            $table->date('dipa_date_snapshot')->nullable()->after('dipa_number_snapshot');

            $table->index('spt_internal_reference', 'transaksi_spt_internal_reference_index');
            $table->index('spt_external_number', 'transaksi_spt_external_number_index');
            $table->foreign('spt_external_number_recorded_by', 'transaksi_spt_external_number_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('dipa_setting_id', 'transaksi_dipa_setting_foreign')
                ->references('id')->on('dipa_settings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->dropForeign('transaksi_spt_external_number_by_foreign');
            $table->dropForeign('transaksi_dipa_setting_foreign');
            $table->dropIndex('transaksi_spt_internal_reference_index');
            $table->dropIndex('transaksi_spt_external_number_index');
            $table->dropColumn([
                'spt_internal_reference',
                'spt_number_mode',
                'spt_external_number',
                'spt_external_number_recorded_at',
                'spt_external_number_recorded_by',
                'spt_template_variant',
                'dipa_setting_id',
                'dipa_fiscal_year_snapshot',
                'dipa_number_snapshot',
                'dipa_date_snapshot',
            ]);
        });

        DB::table('transaksi_perjadin')->whereNull('no_memo')->update(['no_memo' => '-']);
        DB::table('transaksi_perjadin')->whereNull('perihal_memo')->update(['perihal_memo' => '-']);
        Schema::table('transaksi_perjadin', function (Blueprint $table): void {
            $table->string('no_memo')->nullable(false)->change();
            $table->string('perihal_memo')->nullable(false)->change();
        });

        Schema::dropIfExists('dipa_settings');
    }
};
