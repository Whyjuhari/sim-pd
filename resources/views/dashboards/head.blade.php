@extends('layouts.app')
@section('title', 'Monitoring Pimpinan - SIM-PD')
@section('brand', 'Monitoring Pimpinan')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h4 class="mb-1">Ringkasan Perjalanan Dinas</h4><p class="text-muted mb-0">Dashboard read-only untuk monitoring pimpinan.</p></div>
    <form method="GET" class="d-flex align-items-center gap-2"><label for="year" class="fw-semibold">Tahun</label><input id="year" type="number" name="year" min="2000" max="2100" value="{{ $year }}" class="form-control" style="width:110px"><button class="btn btn-primary">Tampilkan</button></form>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Total Perjalanan', $stats['total'], 'briefcase', 'primary'],
        ['Siap Berjalan', $stats['ready'], 'send', 'info'],
        ['Menunggu Verifikasi', $stats['pending'], 'hourglass-split', 'warning'],
        ['Selesai', $stats['approved'], 'check-circle', 'success'],
    ] as [$label, $value, $icon, $color])
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm border-start border-4 border-{{ $color }}"><div class="card-body d-flex justify-content-between"><div><div class="small text-muted fw-bold text-uppercase">{{ $label }}</div><h3 class="mb-0 mt-2">{{ $value }}</h3></div><i class="bi bi-{{ $icon }} fs-2 text-{{ $color }}"></i></div></div></div>
    @endforeach
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-header bg-white fw-bold">Ringkasan Anggaran</div><div class="card-body">
        <div class="text-muted">Estimasi</div><h4>Rp {{ number_format($stats['estimate'], 0, ',', '.') }}</h4>
        <hr><div class="text-muted">Realisasi Disetujui</div><h4 class="text-success">Rp {{ number_format($stats['realized'], 0, ',', '.') }}</h4>
    </div></div></div>
    <div class="col-lg-8"><div class="card shadow-sm h-100"><div class="card-header bg-white fw-bold">Tujuan Terbanyak</div><div class="card-body">
        @forelse($destinations as $destination)
            <div class="d-flex justify-content-between border-bottom py-2"><span>{{ $destination->kota_tujuan }}</span><span class="badge bg-primary">{{ $destination->total }} perjalanan</span></div>
        @empty<p class="text-muted mb-0">Belum ada data.</p>@endforelse
    </div></div></div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">Perjalanan Terbaru</div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle"><thead class="table-light"><tr><th>No. SPT</th><th>Pegawai</th><th>Tujuan</th><th>Tanggal</th><th>Status</th><th>Estimasi</th><th>Realisasi</th></tr></thead><tbody>
        @forelse($travels as $travel)
            <tr><td class="fw-semibold">{{ $travel->no_spt }}</td><td>{{ $travel->pegawai?->nama_lengkap ?? '-' }}</td><td>{{ $travel->kota_tujuan }}</td><td>{{ $travel->tgl_berangkat->format('d/m/Y') }}</td><td><span class="badge status-{{ $travel->status }}">{{ \App\Support\TravelStatus::label($travel->status) }}</span></td><td>Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}</td><td>Rp {{ number_format((float) $travel->total_cair, 0, ',', '.') }}</td></tr>
        @empty<tr><td colspan="7" class="text-center text-muted py-5">Belum ada perjalanan pada tahun ini.</td></tr>@endforelse
        </tbody></table>
    </div>
    @if($travels->hasPages())<div class="card-footer bg-white">{{ $travels->links() }}</div>@endif
</div>
@endsection
