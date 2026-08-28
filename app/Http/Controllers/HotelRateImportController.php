<?php

namespace App\Http\Controllers;

use App\Models\HotelImport;
use App\Models\HotelRegulation;
use App\Models\MasterTarif;
use App\Models\Province;
use App\Services\HotelRateCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HotelRateImportController extends Controller
{
    public function template(): StreamedResponse
    {
        $provincesByCode = Province::query()->get(['code', 'name'])->keyBy('code');
        $provinces = collect(HotelRateCsvService::PDF_PROVINCE_ORDER)
            ->map(fn (string $code) => $provincesByCode->get($code))
            ->filter()->values();
        abort_unless($provinces->count() === 38, 500, 'Master provinsi belum lengkap.');

        return response()->streamDownload(function () use ($provinces): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, HotelRateCsvService::HEADERS);
            foreach ($provinces as $province) {
                fputcsv($output, [$province->name, '']);
            }
            fclose($output);
        }, 'Template_Biaya_Penginapan_PMK_32_2025.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function upload(Request $request, HotelRateCsvService $service): RedirectResponse
    {
        $data = $request->validate([
            'hotel_csv' => [
                'required', 'file', 'max:1024', 'extensions:csv',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
            ],
        ]);
        $import = $service->createValidatedImport($data['hotel_csv'], $request->user());

        return redirect()->route('program.hotel-rates.imports.preview', $import);
    }

    public function preview(HotelImport $import): View|RedirectResponse
    {
        if ($import->status !== HotelImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            return redirect()->route('program.budget.edit')->withErrors([
                'hotel_csv' => 'Pratinjau impor hotel sudah tidak tersedia.',
            ]);
        }

        return view('program.hotel-rate-preview', [
            'import' => $import,
            'rows' => collect($import->parsed_rows),
        ]);
    }

    public function commit(HotelImport $import, HotelRateCsvService $service): RedirectResponse
    {
        $regulation = $service->commit($import);

        return redirect()->route('program.budget.edit')->with(
            'success',
            "Tarif hotel {$regulation->regulation_number} revisi {$regulation->revision} disimpan sebagai draft."
        );
    }

    public function discard(HotelImport $import, HotelRateCsvService $service): RedirectResponse
    {
        $service->discard($import);

        return redirect()->route('program.budget.edit')->with('success', 'Pratinjau impor hotel dibatalkan.');
    }

    public function activate(Request $request, HotelRegulation $regulation): RedirectResponse
    {
        if ($regulation->status !== HotelRegulation::STATUS_DRAFT) {
            throw ValidationException::withMessages(['hotel_pmk' => 'Hanya versi draft yang dapat diaktifkan.']);
        }
        $missingCities = MasterTarif::query()->whereNull('province_id')->orderBy('kota_tujuan')->pluck('kota_tujuan');
        if ($missingCities->isNotEmpty()) {
            throw ValidationException::withMessages([
                'hotel_pmk' => 'Lengkapi provinsi untuk tujuan berikut sebelum aktivasi: '
                    .$missingCities->take(8)->implode(', ')
                    .($missingCities->count() > 8 ? ', dan lainnya.' : '.'),
            ]);
        }
        if ($regulation->rates()->count() !== 38) {
            throw ValidationException::withMessages(['hotel_pmk' => 'Dataset hotel belum memuat tepat 38 provinsi.']);
        }

        DB::transaction(function () use ($regulation, $request): void {
            $locked = HotelRegulation::query()->lockForUpdate()->findOrFail($regulation->id);
            if ($locked->status !== HotelRegulation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['hotel_pmk' => 'Status versi tarif telah berubah.']);
            }
            HotelRegulation::query()
                ->where('fiscal_year', $locked->fiscal_year)
                ->where('status', HotelRegulation::STATUS_ACTIVE)
                ->whereKeyNot($locked->id)
                ->update(['status' => HotelRegulation::STATUS_INACTIVE]);
            $locked->update([
                'status' => HotelRegulation::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
            ]);
        });

        return back()->with('success', 'Tarif hotel PMK aktif untuk SPT baru TA '.$regulation->fiscal_year.'.');
    }

    public function download(HotelRegulation $regulation): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($regulation->csv_path), 404);

        return response()->download(
            Storage::disk('local')->path($regulation->csv_path),
            $regulation->original_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
