@extends('layouts.app')
@section('title', 'Pratinjau Transportasi Darat - SIM-PD')
@section('brand', 'Pratinjau Transportasi Darat')
@section('page-subtitle', 'Periksa seluruh rute sebelum menyimpan dataset sebagai draft.')

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <h2 class="h5 mb-1">{{ $import->original_filename }}</h2>
            <div class="small text-muted">{{ $rows->count() }} rute · Berlaku one-way · SHA-256 {{ substr($import->csv_sha256, 0, 16) }}…</div>
        </div>
        <span class="badge rounded-pill text-bg-success">CSV valid</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            Rute dipakai dua arah dengan tarif yang sama. Aktivasi hanya memengaruhi SPT darat baru yang rutenya ditemukan.
        </div>
        <div class="table-responsive" style="max-height: 60vh">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light position-sticky top-0">
                    <tr><th>Provinsi</th><th>Ibu Kota</th><th>Kabupaten/Kota</th><th class="text-end">Tarif One-Way</th></tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $row['nama_provinsi'] }}</td>
                            <td>{{ $row['ibukota_provinsi'] }}</td>
                            <td>{{ $row['kabupaten_kota_tujuan'] }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
            <form method="POST" action="{{ route('program.ground-transport.imports.discard', $import) }}">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger w-100"><i class="bi bi-x-circle"></i> Batalkan</button>
            </form>
            <form method="POST" action="{{ route('program.ground-transport.imports.commit', $import) }}">
                @csrf
                <button class="btn btn-primary w-100" data-sim-confirm
                    data-sim-confirm-title="Simpan dataset transportasi darat?"
                    data-sim-confirm-text="Dataset akan disimpan sebagai draft dan belum digunakan sampai diaktifkan."
                    data-sim-confirm-button="Ya, simpan draft" data-sim-confirm-tone="warning">
                    <i class="bi bi-save"></i> Simpan sebagai Draft
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
