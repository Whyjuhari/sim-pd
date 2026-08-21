<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PerjalananDinas;
use App\Services\Documents\SuratTugasDocxGenerator;
use App\Services\Documents\PdfConverter;
use Illuminate\Support\Facades\Gate;

class PegawaiController extends Controller
{
    public function cetak(
        PerjalananDinas $travel,
        SuratTugasDocxGenerator $generator,
        PdfConverter $converter
    ) {
        Gate::authorize('printSpt', $travel);

        $docxPath = $generator->generate([
            'no_spt' => $travel->no_spt,
            'menimbang' => $travel->menimbang,
            'no_memo' => $travel->no_memo,
            'perihal_memo' => $travel->perihal_memo,
            'tgl_memo' => $travel->tgl_memo,
            'maksud_perjalanan' => $travel->maksud_perjalanan,
            'tgl_berangkat' => $travel->tgl_berangkat,
            'tgl_kembali' => $travel->tgl_kembali,
            'lama_hari' => $travel->lama_hari,
            'akun_anggaran' => $travel->akun_anggaran,
            'pegawai_list' => $travel->pegawai()
                ->get(['nama_lengkap', 'nip', 'pangkat_golongan', 'jabatan'])
                ->toArray(),
        ]);

        $pdfPath = $converter->convert($docxPath, config('sim_pd.documents.pdf_dir'));

        return response()->download($pdfPath, "SPT_{$travel->no_spt}.pdf")
            ->deleteFileAfterSend(true);
    }
}
