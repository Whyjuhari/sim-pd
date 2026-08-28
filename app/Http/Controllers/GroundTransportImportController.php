<?php

namespace App\Http\Controllers;

use App\Models\GroundTransportImport;
use App\Models\GroundTransportRegulation;
use App\Models\MasterTarif;
use App\Models\Province;
use App\Services\GroundTransportCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroundTransportImportController extends Controller
{
    public function template(GroundTransportCsvService $service): StreamedResponse
    {
        $provinceNames = Province::query()->pluck('name', 'code');
        $rows = $service->catalogRows();

        return response()->streamDownload(function () use ($rows, $provinceNames): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, GroundTransportCsvService::HEADERS);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $provinceNames[$row['kode_provinsi']] ?? '',
                    $row['ibukota_provinsi'],
                    $row['kabupaten_kota_tujuan'],
                    '',
                ]);
            }
            fclose($output);
        }, 'Template_Transportasi_Darat_PMK_32_2025.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function upload(Request $request, GroundTransportCsvService $service): RedirectResponse
    {
        $data = $request->validate([
            'ground_transport_csv' => [
                'required', 'file', 'max:2048', 'extensions:csv',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
            ],
        ]);
        $import = $service->createValidatedImport($data['ground_transport_csv'], $request->user());

        return redirect()->route('program.ground-transport.imports.preview', $import);
    }

    public function preview(GroundTransportImport $import): View|RedirectResponse
    {
        if ($import->status !== GroundTransportImport::STATUS_VALIDATED || $import->expires_at->isPast()) {
            return redirect()->route('program.budget.edit')->withErrors([
                'ground_transport_csv' => 'Pratinjau impor transportasi darat sudah tidak tersedia.',
            ]);
        }

        return view('program.ground-transport-preview', [
            'import' => $import,
            'rows' => collect($import->parsed_rows),
        ]);
    }

    public function commit(
        GroundTransportImport $import,
        GroundTransportCsvService $service
    ): RedirectResponse {
        $regulation = $service->commit($import);

        return redirect()->route('program.budget.edit')->with(
            'success',
            "Transportasi darat {$regulation->regulation_number} revisi {$regulation->revision} disimpan sebagai draft."
        );
    }

    public function discard(
        GroundTransportImport $import,
        GroundTransportCsvService $service
    ): RedirectResponse {
        $service->discard($import);

        return redirect()->route('program.budget.edit')->with(
            'success',
            'Pratinjau impor transportasi darat dibatalkan.'
        );
    }

    public function activate(
        Request $request,
        GroundTransportRegulation $regulation,
        GroundTransportCsvService $service
    ): RedirectResponse
    {
        if ($regulation->status !== GroundTransportRegulation::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'ground_transport_pmk' => 'Hanya versi draft yang dapat diaktifkan.',
            ]);
        }
        if ($regulation->rates()->count() !== GroundTransportCsvService::EXPECTED_ROUTES) {
            throw ValidationException::withMessages([
                'ground_transport_pmk' => 'Dataset belum memuat tepat 370 rute transportasi darat.',
            ]);
        }

        DB::transaction(function () use ($regulation, $request, $service): void {
            $locked = GroundTransportRegulation::query()->lockForUpdate()->findOrFail($regulation->id);
            if ($locked->status !== GroundTransportRegulation::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'ground_transport_pmk' => 'Status versi tarif telah berubah.',
                ]);
            }

            $rates = $locked->rates()->with('province')->orderBy('id')->get();
            $provinceIds = Province::query()->pluck('id', 'code');
            foreach ($rates as $rate) {
                foreach ([$rate->capital_city, $rate->destination_city] as $destination) {
                    $provinceCode = $service->provinceCodeForLocation(
                        $destination,
                        (string) $rate->province->code
                    );
                    $destinationProvinceId = $provinceIds[$provinceCode] ?? null;
                    if (! $destinationProvinceId) {
                        throw ValidationException::withMessages([
                            'ground_transport_pmk' => "Provinsi tujuan {$destination} tidak ditemukan.",
                        ]);
                    }
                    $existing = MasterTarif::query()->where('kota_tujuan', $destination)->first();
                    if ($existing && $existing->province_id && (int) $existing->province_id !== (int) $destinationProvinceId) {
                        throw ValidationException::withMessages([
                            'ground_transport_pmk' => "Provinsi tujuan {$destination} berbeda dengan dataset PMK.",
                        ]);
                    }
                    if ($existing) {
                        if (! $existing->province_id) {
                            $existing->update(['province_id' => $destinationProvinceId]);
                        }
                        continue;
                    }
                    MasterTarif::query()->create([
                        'kota_tujuan' => $destination,
                        'province_id' => $destinationProvinceId,
                        'ground_transport_source' => 'pmk',
                        'uang_saku_per_hari' => 0,
                    ]);
                }
            }

            GroundTransportRegulation::query()
                ->where('fiscal_year', $locked->fiscal_year)
                ->where('status', GroundTransportRegulation::STATUS_ACTIVE)
                ->whereKeyNot($locked->id)
                ->update(['status' => GroundTransportRegulation::STATUS_INACTIVE]);
            $locked->update([
                'status' => GroundTransportRegulation::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
            ]);
        });

        return back()->with(
            'success',
            'Tarif transportasi darat PMK aktif untuk SPT baru TA '.$regulation->fiscal_year.'.'
        );
    }

    public function download(GroundTransportRegulation $regulation): BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($regulation->csv_path), 404);

        return response()->download(
            Storage::disk('local')->path($regulation->csv_path),
            $regulation->original_filename,
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }
}
