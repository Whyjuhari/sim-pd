@extends('layouts.app')
@section('title', 'Pratinjau Tarif PMK - SIM-PD')
@section('brand', 'Pratinjau Tarif PMK')
@section('page-subtitle', 'Periksa seluruh nominal sebelum menyimpan dataset sebagai versi draft.')

@section('page-actions')
    <form method="POST" action="{{ route('program.daily-allowances.imports.discard', $import) }}">
        @csrf @method('DELETE')
        <button class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Batalkan Impor</button>
    </form>
@endsection

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
            <div class="fw-semibold">PMK Nomor 32 Tahun 2025 · TA 2026</div>
            <small class="text-muted">{{ $import->original_filename }} · SHA-256 {{ $import->csv_sha256 }}</small>
        </div>
        <span class="badge rounded-pill text-bg-success"><i class="bi bi-check-circle"></i> 38 provinsi valid</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height: 65vh;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr><th>Kode</th><th>Provinsi</th><th class="text-end">Luar Kota</th><th class="text-end">Dalam Kota &gt; 8 Jam</th><th class="text-end">Diklat</th></tr>
                </thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['kode_provinsi'] }}</td>
                        <td class="fw-semibold">{{ $row['nama_provinsi'] }}</td>
                        <td class="text-end">Rp {{ number_format($row['outside_city'], 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format($row['inside_city_over_8_hours'], 0, ',', '.') }}</td>
                        <td class="text-end">Rp {{ number_format($row['training'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <small class="text-muted">Penyimpanan ini belum mengaktifkan tarif untuk SPT.</small>
        <form method="POST" action="{{ route('program.daily-allowances.imports.commit', $import) }}">
            @csrf
            <button class="btn btn-primary" data-sim-confirm
                data-sim-confirm-title="Simpan dataset sebagai draft?"
                data-sim-confirm-text="Pastikan seluruh nominal sudah sama dengan Lampiran Tabel 28.1. Aktivasi dilakukan terpisah setelah pemetaan provinsi selesai."
                data-sim-confirm-button="Ya, simpan draft"
                data-sim-confirm-tone="warning"><i class="bi bi-save"></i> Simpan sebagai Draft</button>
        </form>
    </div>
</div>
@endsection
