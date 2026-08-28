@extends('layouts.app')
@section('title', 'Pratinjau Tarif Hotel PMK - SIM-PD')
@section('brand', 'Pratinjau Tarif Hotel PMK')
@section('page-subtitle', 'Periksa tarif Eselon IV/Golongan III–I sebelum menyimpan dataset sebagai draft.')

@section('page-actions')
    <form method="POST" action="{{ route('program.hotel-rates.imports.discard', $import) }}">
        @csrf @method('DELETE')
        <button class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Batalkan Impor</button>
    </form>
@endsection

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
            <div class="fw-semibold">PMK Nomor 32 Tahun 2025 · Biaya Penginapan TA 2026</div>
            <small class="text-muted">{{ $import->original_filename }} · SHA-256 {{ $import->csv_sha256 }}</small>
        </div>
        <span class="badge rounded-pill text-bg-success"><i class="bi bi-check-circle"></i> 38 provinsi valid</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 65vh;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Provinsi</th><th class="text-end">Eselon IV/Golongan III/II/I per Malam</th></tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td class="fw-semibold">{{ $row['nama_provinsi'] }}</td>
                        <td class="text-end">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <small class="text-muted">Penyimpanan belum mengaktifkan tarif untuk SPT.</small>
        <form method="POST" action="{{ route('program.hotel-rates.imports.commit', $import) }}">
            @csrf
            <button class="btn btn-primary" data-sim-confirm
                data-sim-confirm-title="Simpan tarif hotel sebagai draft?"
                data-sim-confirm-text="Pastikan nominal sesuai Lampiran I Tabel 30. Aktivasi dilakukan terpisah."
                data-sim-confirm-button="Ya, simpan draft" data-sim-confirm-tone="warning">
                <i class="bi bi-save"></i> Simpan sebagai Draft
            </button>
        </form>
    </div>
</div>
@endsection
