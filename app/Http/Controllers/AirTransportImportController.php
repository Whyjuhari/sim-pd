<?php

namespace App\Http\Controllers;

use App\Models\AirTransportImport;
use App\Models\AirTransportRegulation;
use App\Models\MasterTarif;
use App\Services\AirTransportCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AirTransportImportController extends Controller
{
    public function terminalTemplate(AirTransportCsvService $service): StreamedResponse
    {
        return response()->streamDownload(function () use ($service): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, AirTransportCsvService::TERMINAL_HEADERS);
            foreach ($service->terminalCatalogRows() as $row) {
                fputcsv($output, [$row['nama_provinsi'], $row['tarif_sumber']]);
            }
            fclose($output);
        }, 'Referensi_Transportasi_Terminal_PMK_32_2025.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function airfareTemplate(AirTransportCsvService $service): StreamedResponse
    {
        return response()->streamDownload(function () use ($service): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, AirTransportCsvService::AIRFARE_HEADERS);
            foreach ($service->airfareCatalogRows() as $row) {
                fputcsv($output, [
                    $row['kota_asal'], $row['kota_tujuan'],
                    $row['tarif_bisnis_sumber'], $row['tarif_ekonomi_sumber'],
                ]);
            }
            fclose($output);
        }, 'Referensi_Tiket_Pesawat_PMK_32_2025.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function upload(Request $request, AirTransportCsvService $service): RedirectResponse
    {
        $rules = ['required', 'file', 'max:2048', 'extensions:csv', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'];
        $data = $request->validate([
            'terminal_transport_csv' => $rules,
            'airfare_csv' => $rules,
        ]);
        $import = $service->createValidatedImport(
            $data['terminal_transport_csv'],
            $data['airfare_csv'],
            $request->user()
        );

        return redirect()->route('program.air-transport.imports.preview', $import);
    }

    public function preview(AirTransportImport $import): View|RedirectResponse
    {
        if ($import->status !== AirTransportImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            return redirect()->route('program.budget.edit')->withErrors([
                'air_transport_csv' => 'Pratinjau impor transportasi udara sudah tidak tersedia.',
            ]);
        }

        return view('program.air-transport-preview', [
            'import' => $import,
            'terminalRows' => collect($import->terminal_rows),
            'airfareRows' => collect($import->airfare_rows),
        ]);
    }

    public function commit(AirTransportImport $import, AirTransportCsvService $service): RedirectResponse
    {
        $regulation = $service->commit($import);
        return redirect()->route('program.budget.edit')->with(
            'success',
            "Transportasi udara {$regulation->regulation_number} revisi {$regulation->revision} disimpan sebagai draft."
        );
    }

    public function discard(AirTransportImport $import, AirTransportCsvService $service): RedirectResponse
    {
        $service->discard($import);
        return redirect()->route('program.budget.edit')->with('success', 'Pratinjau transportasi udara dibatalkan.');
    }

    public function activate(
        Request $request,
        AirTransportRegulation $regulation,
        AirTransportCsvService $service
    ): RedirectResponse {
        if ($regulation->status !== AirTransportRegulation::STATUS_DRAFT) {
            throw ValidationException::withMessages(['air_transport_pmk' => 'Hanya versi draft yang dapat diaktifkan.']);
        }
        if (
            $regulation->terminalRates()->count() !== AirTransportCsvService::EXPECTED_TERMINAL_RATES
            || $regulation->airfareRates()->count() !== AirTransportCsvService::EXPECTED_AIRFARE_ROUTES
        ) {
            throw ValidationException::withMessages(['air_transport_pmk' => 'Kedua dataset belum lengkap.']);
        }

        DB::transaction(function () use ($request, $regulation, $service): void {
            $locked = AirTransportRegulation::query()->lockForUpdate()->findOrFail($regulation->id);
            if ($locked->status !== AirTransportRegulation::STATUS_DRAFT) {
                throw ValidationException::withMessages(['air_transport_pmk' => 'Status versi tarif telah berubah.']);
            }

            foreach (MasterTarif::query()->whereNull('airfare_city')->get() as $tariff) {
                $canonical = $service->canonicalAirfareCity($tariff->kota_tujuan);
                if ($canonical) {
                    $tariff->update([
                        'airfare_city' => $canonical,
                        'airfare_city_key' => $service->normalizeCity($canonical),
                    ]);
                }
            }
            AirTransportRegulation::query()
                ->where('fiscal_year', $locked->fiscal_year)
                ->where('status', AirTransportRegulation::STATUS_ACTIVE)
                ->whereKeyNot($locked->id)
                ->update(['status' => AirTransportRegulation::STATUS_INACTIVE]);
            $locked->update([
                'status' => AirTransportRegulation::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
            ]);
        });

        return back()->with('success', 'Tarif transportasi terminal dan tiket pesawat PMK aktif untuk SPT baru TA '.$regulation->fiscal_year.'.');
    }

    public function downloadTerminal(AirTransportRegulation $regulation): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($regulation->terminal_csv_path), 404);
        return response()->download(
            Storage::disk('local')->path($regulation->terminal_csv_path),
            $regulation->terminal_original_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function downloadAirfare(AirTransportRegulation $regulation): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($regulation->airfare_csv_path), 404);
        return response()->download(
            Storage::disk('local')->path($regulation->airfare_csv_path),
            $regulation->airfare_original_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
