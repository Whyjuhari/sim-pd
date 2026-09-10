<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaksi_perjadin')) {
            return;
        }

        $addExternalNumber = ! Schema::hasColumn(
            'transaksi_perjadin',
            'spt_external_number'
        );

        $addRecordedAt = ! Schema::hasColumn(
            'transaksi_perjadin',
            'spt_external_number_recorded_at'
        );

        $addRecordedBy = ! Schema::hasColumn(
            'transaksi_perjadin',
            'spt_external_number_recorded_by'
        );

        if ($addExternalNumber || $addRecordedAt || $addRecordedBy) {
            Schema::table('transaksi_perjadin', function (Blueprint $table) use (
                $addExternalNumber,
                $addRecordedAt,
                $addRecordedBy
            ): void {
                if ($addExternalNumber) {
                    $table->string('spt_external_number', 50)
                        ->nullable()
                        ->after('spt_number_mode');
                }

                if ($addRecordedAt) {
                    $table->timestamp('spt_external_number_recorded_at')
                        ->nullable()
                        ->after('spt_external_number');
                }

                if ($addRecordedBy) {
                    $table->integer('spt_external_number_recorded_by')
                        ->nullable()
                        ->after('spt_external_number_recorded_at');
                }
            });
        }

        if (! Schema::hasIndex(
            'transaksi_perjadin',
            'transaksi_spt_external_number_index'
        )) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->index(
                    'spt_external_number',
                    'transaksi_spt_external_number_index'
                );
            });
        }

        if (! Schema::hasForeignKey(
            'transaksi_perjadin',
            'transaksi_spt_external_number_by_foreign'
        )) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->foreign(
                    'spt_external_number_recorded_by',
                    'transaksi_spt_external_number_by_foreign'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        $hasLegacyRecordedAt = Schema::hasColumn(
            'transaksi_perjadin',
            'spt_number_finalized_at'
        );

        $hasLegacyRecordedBy = Schema::hasColumn(
            'transaksi_perjadin',
            'spt_number_finalized_by'
        );

        /*
         * Memindahkan nomor Srikandi dari implementasi lama.
         * Pada implementasi lama nomor resmi disimpan di no_spt.
         */
        if ($hasLegacyRecordedAt) {
            DB::table('transaksi_perjadin')
                ->whereNull('spt_external_number')
                ->where('spt_number_mode', 'external_parameter')
                ->whereNotNull('spt_number_finalized_at')
                ->whereNotNull('no_spt')
                ->where('no_spt', '!=', '')
                ->whereNotIn('no_spt', [
                    '${nomor_naskah}',
                    '${nomor_surat}',
                ])
                ->update([
                    'spt_external_number' => DB::raw('no_spt'),
                ]);

            $auditValues = [
                'spt_external_number_recorded_at' => DB::raw(
                    'spt_number_finalized_at'
                ),
            ];

            if ($hasLegacyRecordedBy) {
                $auditValues['spt_external_number_recorded_by'] = DB::raw(
                    'spt_number_finalized_by'
                );
            }

            DB::table('transaksi_perjadin')
                ->whereNull('spt_external_number_recorded_at')
                ->whereNotNull('spt_number_finalized_at')
                ->update($auditValues);
        }
    }

    public function down(): void
    {
        /*
         * Hanya rollback pada database lama yang memiliki kolom finalized_*.
         * Database baru sudah memperoleh kolom external_number dari migrasi
         * utama sehingga kolom tersebut tidak boleh dihapus.
         */
        $isLegacyDatabase =
            Schema::hasColumn(
                'transaksi_perjadin',
                'spt_number_finalized_at'
            )
            || Schema::hasColumn(
                'transaksi_perjadin',
                'spt_number_finalized_by'
            );

        if (! $isLegacyDatabase) {
            return;
        }

        if (Schema::hasForeignKey(
            'transaksi_perjadin',
            'transaksi_spt_external_number_by_foreign'
        )) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->dropForeign(
                    'transaksi_spt_external_number_by_foreign'
                );
            });
        }

        if (Schema::hasIndex(
            'transaksi_perjadin',
            'transaksi_spt_external_number_index'
        )) {
            Schema::table('transaksi_perjadin', function (Blueprint $table): void {
                $table->dropIndex(
                    'transaksi_spt_external_number_index'
                );
            });
        }

        $columns = array_values(array_filter([
            'spt_external_number',
            'spt_external_number_recorded_at',
            'spt_external_number_recorded_by',
        ], fn(string $column): bool => Schema::hasColumn(
            'transaksi_perjadin',
            $column
        )));

        if ($columns !== []) {
            Schema::table('transaksi_perjadin', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
