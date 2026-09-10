<?php

namespace App\Http\Controllers;

use App\Models\BudgetAccount;
use App\Models\DailyAllowanceRegulation;
use App\Models\DipaSetting;
use App\Models\HotelRegulation;
use App\Models\GroundTransportRegulation;
use App\Models\AirTransportRegulation;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Province;
use App\Services\DailyAllowanceCsvService;
use App\Services\HotelRateCsvService;
use App\Services\GroundTransportCsvService;
use App\Services\AirTransportCsvService;
use App\Services\PmkReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetSettingController extends Controller
{
    public function edit(
        Request $request,
        DailyAllowanceCsvService $csvService,
        HotelRateCsvService $hotelCsvService,
        GroundTransportCsvService $groundTransportCsvService,
        AirTransportCsvService $airTransportCsvService,
        PmkReadinessService $pmkReadiness
    ): View
    {
        $csvService->cleanupExpired();
        $hotelCsvService->cleanupExpired();
        $groundTransportCsvService->cleanupExpired();
        $airTransportCsvService->cleanupExpired();
        $search = trim((string) $request->query('q', ''));
        $readinessYear = min(2100, max(2000, $request->integer('readiness_year', now()->year)));

        return view('program.budget', [
            'search' => $search,
            'tariffs' => MasterTarif::query()
                ->with('province')
                ->when($search !== '', fn ($query) => $query->where('kota_tujuan', 'like', "%{$search}%"))
                ->orderBy('kota_tujuan')
                ->paginate(15, ['*'], 'tariff_page')
                ->withQueryString(),
            'provinces' => Province::query()->orderBy('name')->get(),
            'dailyAllowanceRegulations' => DailyAllowanceRegulation::query()
                ->with(['uploader', 'activator'])
                ->withCount('rates')
                ->orderByDesc('fiscal_year')
                ->orderByDesc('revision')
                ->get(),
            'hotelRegulations' => HotelRegulation::query()
                ->with(['uploader', 'activator'])
                ->withCount('rates')
                ->orderByDesc('fiscal_year')
                ->orderByDesc('revision')
                ->get(),
            'groundTransportRegulations' => GroundTransportRegulation::query()
                ->with(['uploader', 'activator'])
                ->withCount('rates')
                ->orderByDesc('fiscal_year')
                ->orderByDesc('revision')
                ->get(),
            'airTransportRegulations' => AirTransportRegulation::query()
                ->with(['uploader', 'activator'])
                ->withCount(['terminalRates', 'airfareRates'])
                ->orderByDesc('fiscal_year')
                ->orderByDesc('revision')
                ->get(),
            'airfareCities' => $airTransportCsvService->airfareCities(),
            'pmkReadiness' => $pmkReadiness->summary($readinessYear),
            'readinessYears' => $pmkReadiness->availableYears(),
            'dipaSettings' => DipaSetting::query()
                ->with('updater')
                ->orderByDesc('fiscal_year')
                ->get(),
            'accounts' => BudgetAccount::query()
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->paginate(15, ['*'], 'account_page')
                ->withQueryString(),
        ]);
    }

    public function upsertDipa(Request $request, int $fiscalYear): RedirectResponse
    {
        abort_unless($fiscalYear >= 2000 && $fiscalYear <= 2100, 404);

        $data = $request->validate([
            'document_number' => ['required', 'string', 'max:100'],
            'document_date' => ['required', 'date'],
        ]);

        DipaSetting::query()->updateOrCreate(
            ['fiscal_year' => $fiscalYear],
            [
                ...$data,
                'updated_by' => (int) $request->user()->id,
            ]
        );

        return back()->with('success', "Konfigurasi DIPA TA {$fiscalYear} berhasil disimpan.");
    }

    public function storeTariff(Request $request, AirTransportCsvService $airTransportCsvService): RedirectResponse
    {
        $data = $request->validate([
            'kota_tujuan' => ['required', 'string', 'max:50', 'unique:master_tarif,kota_tujuan'],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'airfare_city' => ['nullable', 'string', 'max:80'],
        ]);
        $data = $this->normalizeAirfareCity($data, $airTransportCsvService);
        $data['uang_saku_per_hari'] = 0;
        MasterTarif::query()->create($data);

        return back()->with('success', 'Tujuan dan pemetaan PMK berhasil ditambahkan.');
    }

    public function updateTariff(Request $request, MasterTarif $tariff, AirTransportCsvService $airTransportCsvService): RedirectResponse
    {
        $data = $request->validate([
            'kota_tujuan' => [
                'required', 'string', 'max:50',
                Rule::unique('master_tarif', 'kota_tujuan')->ignore($tariff->id),
            ],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'airfare_city' => ['nullable', 'string', 'max:80'],
        ]);
        $data = $this->normalizeAirfareCity($data, $airTransportCsvService);
        $tariff->update($data);

        return back()->with('success', 'Pemetaan tujuan berhasil diperbarui.');
    }

    public function destroyTariff(MasterTarif $tariff): RedirectResponse
    {
        if (PerjalananDinas::query()->where('kota_tujuan', $tariff->kota_tujuan)->exists()) {
            throw ValidationException::withMessages([
                'tarif' => 'Tujuan tidak dapat dihapus karena sudah digunakan dalam perjalanan dinas.',
            ]);
        }
        $tariff->delete();

        return back()->with('success', 'Tujuan berhasil dihapus.');
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'unique:budget_accounts,code'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        BudgetAccount::query()->create([...$data, 'is_active' => true]);

        return back()->with('success', 'MAK berhasil ditambahkan.');
    }

    public function updateAccount(Request $request, BudgetAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', Rule::unique('budget_accounts', 'code')->ignore($account->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);
        $account->update($data);

        return back()->with('success', 'MAK berhasil diperbarui.');
    }

    private function normalizeAirfareCity(array $data, AirTransportCsvService $service): array
    {
        $value = trim((string) ($data['airfare_city'] ?? ''));
        if ($value === '') {
            $data['airfare_city'] = null;
            $data['airfare_city_key'] = null;
            return $data;
        }
        $canonical = $service->canonicalAirfareCity($value);
        if (! $canonical) {
            throw ValidationException::withMessages([
                'airfare_city' => 'Kota bandara harus dipilih dari pasangan kota pada dataset PMK.',
            ]);
        }
        $data['airfare_city'] = $canonical;
        $data['airfare_city_key'] = $service->normalizeCity($canonical);
        return $data;
    }
}
