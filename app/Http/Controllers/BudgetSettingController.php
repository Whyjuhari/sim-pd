<?php

namespace App\Http\Controllers;

use App\Models\BudgetAccount;
use App\Models\MasterTarif;
use App\Models\PerjalananDinas;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BudgetSettingController extends Controller
{
    public function edit(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('program.budget', [
            'search' => $search,
            'settings' => Setting::query()
                ->whereIn('nama_setting', ['batas_hotel', 'pagu_tiket', 'batas_transport_darat'])
                ->pluck('nilai_setting', 'nama_setting'),
            'tariffs' => MasterTarif::query()
                ->when($search !== '', fn ($query) => $query->where('kota_tujuan', 'like', "%{$search}%"))
                ->orderBy('kota_tujuan')
                ->paginate(15, ['*'], 'tariff_page')
                ->withQueryString(),
            'accounts' => BudgetAccount::query()
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->paginate(15, ['*'], 'account_page')
                ->withQueryString(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'batas_hotel' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'pagu_tiket' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'batas_transport_darat' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        foreach ($data as $name => $value) {
            Setting::query()->updateOrCreate(
                ['nama_setting' => $name],
                ['nilai_setting' => $value]
            );
        }

        return back()->with('success', 'Batas biaya berhasil diperbarui untuk SPT baru.');
    }

    public function storeTariff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kota_tujuan' => ['required', 'string', 'max:50', 'unique:master_tarif,kota_tujuan'],
            'uang_saku_per_hari' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        MasterTarif::query()->create($data);

        return back()->with('success', 'Tarif tujuan berhasil ditambahkan.');
    }

    public function updateTariff(Request $request, MasterTarif $tariff): RedirectResponse
    {
        $data = $request->validate([
            'kota_tujuan' => [
                'required', 'string', 'max:50',
                Rule::unique('master_tarif', 'kota_tujuan')->ignore($tariff->id),
            ],
            'uang_saku_per_hari' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);
        $tariff->update($data);

        return back()->with('success', 'Tarif tujuan berhasil diperbarui.');
    }

    public function destroyTariff(MasterTarif $tariff): RedirectResponse
    {
        if (PerjalananDinas::query()->where('kota_tujuan', $tariff->kota_tujuan)->exists()) {
            throw ValidationException::withMessages([
                'tarif' => 'Tarif tidak dapat dihapus karena sudah digunakan. Ubah nilainya jika diperlukan.',
            ]);
        }
        $tariff->delete();

        return back()->with('success', 'Tarif tujuan berhasil dihapus.');
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
}
