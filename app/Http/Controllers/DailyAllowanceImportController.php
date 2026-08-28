<?php

namespace App\Http\Controllers;

use App\Models\DailyAllowanceImport;
use App\Models\DailyAllowanceRegulation;
use App\Models\MasterTarif;
use App\Models\Province;
use App\Services\DailyAllowanceCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DailyAllowanceImportController extends Controller
{
    public function template(): StreamedResponse
    {
        $provincesByCode = Province::query()->get(['code', 'name'])->keyBy('code');
        $provinces = collect(DailyAllowanceCsvService::PDF_PROVINCE_ORDER)
            ->map(fn(string $code) => $provincesByCode->get($code))
            ->filter()
            ->values();
        abort_unless($provinces->count() === 38, 500, 'Master provinsi belum lengkap.');

        return response()->streamDownload(function () use ($provinces): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, DailyAllowanceCsvService::HEADERS);
            foreach ($provinces as $province) {
                fputcsv($output, [$province->code, $province->name, '', '', '']);
            }
            fclose($output);
        }, 'Template_Uang_Harian_PMK_32_2025.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function upload(Request $request, DailyAllowanceCsvService $service): RedirectResponse
    {
        $data = $request->validate([
            'csv' => [
                'required',
                'file',
                'max:1024',
                'extensions:csv',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
            ],
        ]);

        $import = $service->createValidatedImport($data['csv'], $request->user());

        return redirect()->route('program.daily-allowances.imports.preview', $import);
    }

    public function preview(DailyAllowanceImport $import): View|RedirectResponse
    {
        if ($import->status !== DailyAllowanceImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            return redirect()->route('program.budget.edit')->withErrors([
                'csv' => 'Pratinjau impor sudah tidak tersedia.',
            ]);
        }

        return view('program.daily-allowance-preview', [
            'import' => $import,
            'rows' => collect($import->parsed_rows),
        ]);
    }

    public function commit(
        DailyAllowanceImport $import,
        DailyAllowanceCsvService $service
    ): RedirectResponse {
        $regulation = $service->commit($import);

        return redirect()->route('program.budget.edit')->with(
            'success',
            "Tarif {$regulation->regulation_number} revisi {$regulation->revision} disimpan sebagai draft."
        );
    }

    public function discard(
        DailyAllowanceImport $import,
        DailyAllowanceCsvService $service
    ): RedirectResponse {
        $service->discard($import);

        return redirect()->route('program.budget.edit')->with('success', 'Pratinjau impor dibatalkan.');
    }

    public function activate(Request $request, DailyAllowanceRegulation $regulation): RedirectResponse
    {
        if ($regulation->status !== DailyAllowanceRegulation::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'pmk' => 'Hanya versi draft yang dapat diaktifkan.',
            ]);
        }

        $missingCities = MasterTarif::query()
            ->whereNull('province_id')
            ->orderBy('kota_tujuan')
            ->pluck('kota_tujuan');
        if ($missingCities->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pmk' => 'Lengkapi provinsi untuk tujuan berikut sebelum aktivasi: '.$missingCities->take(8)->implode(', ')
                    .($missingCities->count() > 8 ? ', dan lainnya.' : '.'),
            ]);
        }

        if ($regulation->rates()->count() !== Province::query()->count() || $regulation->rates()->count() !== 38) {
            throw ValidationException::withMessages([
                'pmk' => 'Dataset tarif belum memuat tepat 38 provinsi.',
            ]);
        }

        DB::transaction(function () use ($regulation, $request): void {
            $locked = DailyAllowanceRegulation::query()->lockForUpdate()->findOrFail($regulation->id);
            if ($locked->status !== DailyAllowanceRegulation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['pmk' => 'Status versi tarif telah berubah.']);
            }

            DailyAllowanceRegulation::query()
                ->where('fiscal_year', $locked->fiscal_year)
                ->where('status', DailyAllowanceRegulation::STATUS_ACTIVE)
                ->whereKeyNot($locked->id)
                ->update(['status' => DailyAllowanceRegulation::STATUS_INACTIVE]);

            $locked->update([
                'status' => DailyAllowanceRegulation::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
            ]);
        });

        return back()->with('success', 'Tarif PMK berhasil diaktifkan untuk SPT baru TA '.$regulation->fiscal_year.'.');
    }

    public function download(DailyAllowanceRegulation $regulation): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($regulation->csv_path), 404);

        return response()->download(
            Storage::disk('local')->path($regulation->csv_path),
            $regulation->original_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
