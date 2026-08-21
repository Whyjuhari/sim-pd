@extends('layouts.app')
@section('title', 'Master Anggaran - SIM-PD')
@section('brand', 'Master Anggaran')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Master Anggaran</h4>
        <p class="text-muted mb-0">Perubahan hanya berlaku untuk SPT yang dibuat setelah tarif diperbarui.</p>
    </div>
    <a href="{{ route('dashboard.program') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Monitoring</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-bold">Batas Biaya</div>
    <div class="card-body">
        <form method="POST" action="{{ route('program.budget.settings.update') }}" class="row g-3">
            @csrf @method('PUT')
            @foreach([
                'batas_hotel' => 'Batas Hotel per Hari',
                'pagu_tiket' => 'Pagu Tiket Pesawat',
                'batas_transport_darat' => 'Batas Transportasi Darat',
            ] as $key => $label)
                <div class="col-md-4">
                    <label for="{{ $key }}" class="form-label fw-semibold">{{ $label }}</label>
                    <div class="input-group">
                        <span class="input-group-text">Rp</span>
                        <input id="{{ $key }}" type="number" min="0" step="1" name="{{ $key }}"
                            value="{{ old($key, (float) ($settings[$key] ?? 0)) }}" class="form-control" required>
                    </div>
                </div>
            @endforeach
            <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-save"></i> Simpan Batas Biaya</button></div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Tarif Uang Harian per Tujuan</span>
                <form method="GET" class="d-flex gap-2">
                    <input name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari kota">
                    <button class="btn btn-sm btn-outline-primary" aria-label="Cari tarif"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('program.tariffs.store') }}" class="row g-2 mb-4">
                    @csrf
                    <div class="col-md-5"><label for="new-city" class="visually-hidden">Kota tujuan</label><input id="new-city" name="kota_tujuan" class="form-control" placeholder="Kota tujuan" required></div>
                    <div class="col-md-5"><label for="new-rate" class="visually-hidden">Uang harian</label><input id="new-rate" type="number" min="0" name="uang_saku_per_hari" class="form-control" placeholder="Uang harian" required></div>
                    <div class="col-md-2"><button class="btn btn-success w-100">Tambah</button></div>
                </form>
                @foreach($tariffs as $tariff)
                    <form method="POST" action="{{ route('program.tariffs.update', $tariff) }}" class="row g-2 align-items-center border-top py-2">
                        @csrf @method('PUT')
                        <div class="col-md-5"><input name="kota_tujuan" value="{{ $tariff->kota_tujuan }}" class="form-control form-control-sm" aria-label="Kota tujuan" required></div>
                        <div class="col-md-4"><input type="number" min="0" name="uang_saku_per_hari" value="{{ (float) $tariff->uang_saku_per_hari }}" class="form-control form-control-sm" aria-label="Uang harian" required></div>
                        <div class="col-md-3 d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary flex-fill">Simpan</button>
                            <button type="submit" form="delete-tariff-{{ $tariff->id }}" class="btn btn-sm btn-outline-danger" aria-label="Hapus tarif {{ $tariff->kota_tujuan }}" onclick="return confirm('Hapus tarif ini?')"><i class="bi bi-trash"></i></button>
                        </div>
                    </form>
                    <form id="delete-tariff-{{ $tariff->id }}" method="POST" action="{{ route('program.tariffs.destroy', $tariff) }}" class="d-none">@csrf @method('DELETE')</form>
                @endforeach
                {{ $tariffs->links() }}
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">Mata Anggaran (MAK)</div>
            <div class="card-body">
                <form method="POST" action="{{ route('program.accounts.store') }}" class="mb-4">
                    @csrf
                    <label for="new-account" class="form-label">Kode MAK</label>
                    <input id="new-account" name="code" maxlength="50" class="form-control mb-2" required>
                    <label for="new-account-description" class="form-label">Keterangan</label>
                    <input id="new-account-description" name="description" class="form-control mb-2">
                    <button class="btn btn-success w-100">Tambah MAK</button>
                </form>
                @foreach($accounts as $account)
                    <form method="POST" action="{{ route('program.accounts.update', $account) }}" class="border-top py-3">
                        @csrf @method('PUT')
                        <input name="code" value="{{ $account->code }}" maxlength="50" class="form-control form-control-sm mb-2" aria-label="Kode MAK" required>
                        <input name="description" value="{{ $account->description }}" class="form-control form-control-sm mb-2" aria-label="Keterangan MAK">
                        <div class="d-flex gap-2 align-items-center">
                            <select name="is_active" class="form-select form-select-sm" aria-label="Status MAK">
                                <option value="1" @selected($account->is_active)>Aktif</option>
                                <option value="0" @selected(!$account->is_active)>Nonaktif</option>
                            </select>
                            <button class="btn btn-sm btn-outline-primary">Simpan</button>
                        </div>
                    </form>
                @endforeach
                {{ $accounts->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
