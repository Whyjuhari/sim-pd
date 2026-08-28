@extends('layouts.app')
@section('title', 'Pratinjau Transportasi Udara - SIM-PD')
@section('brand', 'Pratinjau Transportasi Udara')
@section('page-subtitle', 'Periksa tarif terminal dan tiket sebagai satu revisi sebelum disimpan.')

@section('content')
<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <h2 class="h5 mb-1">PMK 32/2025 · Transportasi Udara</h2>
            <div class="small text-muted">{{ $terminalRows->count() }} provinsi terminal · {{ $airfareRows->count() }} pasangan tiket</div>
        </div>
        <span class="badge rounded-pill text-bg-success">Kedua CSV valid</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info">Kedua dataset akan disimpan dan diaktifkan sebagai satu revisi. Tarif tiket kelas ekonomi digunakan pada SPT baru.</div>
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#terminal-rates" type="button">Transport Terminal</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#airfare-rates" type="button">Tiket Pesawat</button></li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-2">
            <div id="terminal-rates" class="tab-pane fade show active">
                <div class="table-responsive" style="max-height:55vh"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light position-sticky top-0"><tr><th>Provinsi</th><th class="text-end">Orang/Kali</th></tr></thead>
                    <tbody>@foreach($terminalRows as $row)<tr><td>{{ $row['nama_provinsi'] }}</td><td class="text-end fw-semibold">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td></tr>@endforeach</tbody>
                </table></div>
            </div>
            <div id="airfare-rates" class="tab-pane fade">
                <div class="table-responsive" style="max-height:55vh"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light position-sticky top-0"><tr><th>No.</th><th>Asal</th><th>Tujuan</th><th class="text-end">Bisnis PP</th><th class="text-end">Ekonomi PP</th></tr></thead>
                    <tbody>@foreach($airfareRows as $row)<tr><td>{{ $row['source_number'] }}</td><td>{{ $row['kota_asal'] }}</td><td>{{ $row['kota_tujuan'] }}</td><td class="text-end">Rp {{ number_format($row['business_amount'], 0, ',', '.') }}</td><td class="text-end fw-semibold">Rp {{ number_format($row['economy_amount'], 0, ',', '.') }}</td></tr>@endforeach</tbody>
                </table></div>
            </div>
        </div>
        <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
            <form method="POST" action="{{ route('program.air-transport.imports.discard', $import) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger w-100"><i class="bi bi-x-circle"></i> Batalkan</button></form>
            <form method="POST" action="{{ route('program.air-transport.imports.commit', $import) }}">@csrf
                <button class="btn btn-primary w-100" data-sim-confirm data-sim-confirm-title="Simpan dua dataset?" data-sim-confirm-text="Tarif terminal dan tiket akan disimpan sebagai satu draft dan belum digunakan sampai diaktifkan." data-sim-confirm-button="Ya, simpan draft" data-sim-confirm-tone="warning"><i class="bi bi-save"></i> Simpan sebagai Draft</button>
            </form>
        </div>
    </div>
</div>
@endsection
