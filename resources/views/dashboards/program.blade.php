@extends('layouts.app')
@section('title', 'Monitoring Anggaran - SIM-PD')
@section('brand', 'Monitoring Anggaran')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h4 class="mb-1">Monitoring RAB dan Realisasi</h4><p class="text-muted mb-0">Perbandingan estimasi dan pencairan perjalanan dinas.</p></div>
    <a href="{{ route('program.budget.edit') }}" class="btn btn-primary"><i class="bi bi-sliders"></i> Kelola Master Anggaran</a>
</div>

<div class="row g-3 mb-4">
    @foreach([
        ['Perjalanan', $stats['count'], 'briefcase'],
        ['Total Estimasi', 'Rp '.number_format($stats['estimate'], 0, ',', '.'), 'calculator'],
        ['Total Realisasi', 'Rp '.number_format($stats['realized'], 0, ',', '.'), 'cash-stack'],
        ['Selisih', 'Rp '.number_format($stats['estimate'] - $stats['realized'], 0, ',', '.'), 'bar-chart'],
    ] as [$label, $value, $icon])
        <div class="col-md-6 col-xl-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase fw-bold">{{ $label }}</div>
            <div class="d-flex justify-content-between align-items-center"><h5 class="mb-0 mt-2">{{ $value }}</h5><i class="bi bi-{{ $icon }} fs-2 text-primary opacity-50"></i></div>
        </div></div></div>
    @endforeach
</div>

<div class="card shadow-sm">
    <div class="card-body border-bottom bg-light">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2"><label for="from" class="form-label small">Dari tanggal</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2"><label for="to" class="form-label small">Sampai tanggal</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
            <div class="col-md-3"><label for="destination" class="form-label small">Tujuan</label><select id="destination" name="destination" class="form-select"><option value="">Semua tujuan</option>@foreach($destinations as $destination)<option @selected(($filters['destination'] ?? '') === $destination)>{{ $destination }}</option>@endforeach</select></div>
            <div class="col-md-3"><label for="status" class="form-label small">Status</label><select id="status" name="status" class="form-select"><option value="">Semua status</option><option value="siap_jalan" @selected(($filters['status'] ?? '') === 'siap_jalan')>Siap Berjalan</option><option value="pending_verif" @selected(($filters['status'] ?? '') === 'pending_verif')>Menunggu Verifikasi</option><option value="approved" @selected(($filters['status'] ?? '') === 'approved')>Selesai</option><option value="rejected" @selected(($filters['status'] ?? '') === 'rejected')>Perlu Revisi</option></select></div>
            <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Terapkan</button><a href="{{ route('dashboard.program') }}" class="btn btn-outline-secondary" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a></div>
        </form>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light"><tr><th>Pegawai</th><th>Tujuan</th><th>Estimasi RAB</th><th>Realisasi</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($travels as $travel)
                <tr>
                    <td>{{ $travel->pegawai->nama_lengkap }}</td>
                    <td>{{ $travel->kota_tujuan }}<br><small class="text-muted">{{ $travel->lama_hari }} hari</small></td>
                    <td class="fw-semibold">Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}</td>
                    <td>Rp {{ number_format((float) $travel->total_cair, 0, ',', '.') }}</td>
                    <td><span class="badge status-{{ $travel->status }}">{{ \App\Support\TravelStatus::label($travel->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">Tidak ada data sesuai filter.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($travels->hasPages())<div class="card-footer bg-white">{{ $travels->links() }}</div>@endif
</div>
@endsection
