<?php

use App\Models\PerjalananDinas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realisasi_rincian', function (Blueprint $table): void {
            $table->id();
            $table->integer('perjalanan_dinas_id');
            $table->enum('kategori', ['hotel', 'transportasi']);
            $table->string('kode', 80)->nullable();
            $table->string('uraian', 255);
            $table->decimal('nilai_diajukan', 15, 2)->default(0);
            $table->decimal('nilai_disetujui', 15, 2)->nullable();
            $table->boolean('is_preset')->default(false);
            $table->unsignedSmallInteger('urutan')->default(1);
            $table->timestamps();

            $table->foreign('perjalanan_dinas_id', 'realisasi_rincian_travel_foreign')
                ->references('id')
                ->on('transaksi_perjadin')
                ->cascadeOnDelete();
            $table->unique(
                ['perjalanan_dinas_id', 'kode'],
                'realisasi_rincian_travel_code_unique'
            );
            $table->index(
                ['perjalanan_dinas_id', 'kategori', 'urutan'],
                'realisasi_rincian_travel_category_order_index'
            );
        });

        Schema::table('bukti_realisasi', function (Blueprint $table): void {
            $table->foreignId('realisasi_rincian_id')
                ->nullable()
                ->after('perjalanan_dinas_id');
            $table->foreign('realisasi_rincian_id', 'bukti_realisasi_detail_foreign')
                ->references('id')
                ->on('realisasi_rincian')
                ->cascadeOnDelete();
            $table->index('realisasi_rincian_id', 'bukti_realisasi_detail_index');
        });

        $this->backfillLegacyDetails();
    }

    public function down(): void
    {
        Schema::table('bukti_realisasi', function (Blueprint $table): void {
            $table->dropForeign('bukti_realisasi_detail_foreign');
            $table->dropIndex('bukti_realisasi_detail_index');
            $table->dropColumn('realisasi_rincian_id');
        });

        Schema::dropIfExists('realisasi_rincian');
    }

    private function backfillLegacyDetails(): void
    {
        DB::table('transaksi_perjadin')
            ->select([
                'id', 'status', 'biaya_hotel_real', 'biaya_tiket_real',
                'biaya_hotel_approved', 'biaya_tiket_approved',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($travels): void {
                foreach ($travels as $travel) {
                    $hotelEvidence = DB::table('bukti_realisasi')
                        ->where('perjalanan_dinas_id', $travel->id)
                        ->where('jenis', 'hotel')
                        ->exists();
                    $transportEvidence = DB::table('bukti_realisasi')
                        ->where('perjalanan_dinas_id', $travel->id)
                        ->where('jenis', 'transportasi')
                        ->exists();

                    if ((float) $travel->biaya_hotel_real > 0 || $hotelEvidence) {
                        $detailId = DB::table('realisasi_rincian')->insertGetId([
                            'perjalanan_dinas_id' => $travel->id,
                            'kategori' => 'hotel',
                            'kode' => 'legacy_hotel',
                            'uraian' => 'Hotel Data Lama',
                            'nilai_diajukan' => $travel->biaya_hotel_real,
                            'nilai_disetujui' => $travel->status === PerjalananDinas::STATUS_APPROVED
                                ? $travel->biaya_hotel_approved
                                : null,
                            'is_preset' => true,
                            'urutan' => 10,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('bukti_realisasi')
                            ->where('perjalanan_dinas_id', $travel->id)
                            ->where('jenis', 'hotel')
                            ->update(['realisasi_rincian_id' => $detailId]);
                    }

                    if ((float) $travel->biaya_tiket_real > 0 || $transportEvidence) {
                        $detailId = DB::table('realisasi_rincian')->insertGetId([
                            'perjalanan_dinas_id' => $travel->id,
                            'kategori' => 'transportasi',
                            'kode' => 'legacy_transport',
                            'uraian' => 'Transportasi Data Lama',
                            'nilai_diajukan' => $travel->biaya_tiket_real,
                            'nilai_disetujui' => $travel->status === PerjalananDinas::STATUS_APPROVED
                                ? $travel->biaya_tiket_approved
                                : null,
                            'is_preset' => true,
                            'urutan' => 20,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        DB::table('bukti_realisasi')
                            ->where('perjalanan_dinas_id', $travel->id)
                            ->where('jenis', 'transportasi')
                            ->update(['realisasi_rincian_id' => $detailId]);
                    }
                }
            });
    }
};
