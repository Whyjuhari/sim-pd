@extends('layouts.app')
@section('title', 'Monitoring Pimpinan - SIM-PD')
@section('brand', 'Ringkasan Eksekutif')
@section('page-subtitle', 'Ikhtisar read-only perjalanan dinas, penyerapan anggaran, tujuan, dan aktivitas terbaru.')
@section('page-actions')
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group">
            <a href="{{ route('head.recap.export', ['format' => 'xlsx', 'year' => $year]) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
            <a href="{{ route('head.recap.export', ['format' => 'pdf', 'year' => $year]) }}" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2">
            <label for="year" class="visually-hidden">Tahun monitoring</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-calendar3"></i></span><input id="year" type="number" name="year" min="2000" max="2100" value="{{ $year }}" class="form-control" style="width:100px"><button class="btn btn-primary">Tampilkan</button></div>
        </form>
    </div>
@endsection

@section('content')
    @php($realizationRate = $stats['estimate'] > 0 ? min(100, round(($stats['realized'] / $stats['estimate']) * 100)) : 0)
    <section class="executive-hero mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <div class="executive-kicker">Monitoring Pimpinan · {{ $year }}</div>
                <h2 class="mt-2 mb-2">Perjalanan dinas dalam satu pandangan</h2>
                <p class="mb-0">Informasi utama disusun untuk membantu evaluasi aktivitas, progres verifikasi, dan realisasi anggaran secara cepat.</p>
            </div>
            <div class="col-lg-5">
                <div class="p-3 rounded-4" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.12)">
                    <div class="d-flex justify-content-between mb-2"><span class="small">Penyerapan terhadap estimasi</span><strong>{{ $realizationRate }}%</strong></div>
                    <div class="budget-progress" style="background:rgba(255,255,255,.18)"><div class="budget-progress-bar" style="width:{{ $realizationRate }}%;background:linear-gradient(90deg,#f1c970,#7bd0c9)"></div></div>
                    <div class="small mt-2" style="color:rgba(255,255,255,.72)">Rp {{ number_format($stats['realized'], 0, ',', '.') }} telah disetujui</div>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-4">
        @foreach([
            ['Total Perjalanan', $stats['total'], 'briefcase-fill', 'primary', 'Selama '.$year],
            ['Siap Berjalan', $stats['ready'], 'send-fill', 'info', 'Tugas aktif'],
            ['Menunggu Verifikasi', $stats['pending'], 'hourglass-split', 'warning', 'Dalam proses'],
            ['Selesai', $stats['approved'], 'check-circle-fill', 'success', 'Telah dicairkan'],
        ] as [$label, $value, $icon, $tone, $hint])
            <div class="col-12 col-sm-6 col-xl-3"><x-ui.metric-card :label="$label" :value="$value" :icon="$icon" :tone="$tone" :hint="$hint" /></div>
        @endforeach
    </div>

    <x-ui.pmk-compliance :summary="$pmkCompliance" title="Kepatuhan Biaya PMK" />

    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white"><h2 class="section-title"><span class="section-title-icon"><i class="bi bi-wallet2"></i></span> Ringkasan Anggaran</h2></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4"><div><div class="text-muted small">Total Estimasi</div><h4 class="mt-1 mb-0">Rp {{ number_format($stats['estimate'], 0, ',', '.') }}</h4></div><span class="metric-card-icon" style="color:var(--sim-primary);background:var(--sim-primary-soft)"><i class="bi bi-calculator"></i></span></div>
                    <div class="d-flex justify-content-between align-items-start gap-3"><div><div class="text-muted small">Realisasi Disetujui</div><h4 class="text-success mt-1 mb-0">Rp {{ number_format($stats['realized'], 0, ',', '.') }}</h4></div><span class="metric-card-icon" style="color:var(--sim-success);background:#e5f4eb"><i class="bi bi-cash-stack"></i></span></div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white"><h2 class="section-title"><span class="section-title-icon"><i class="bi bi-geo-alt-fill"></i></span> Tujuan Terbanyak</h2></div>
                <div class="card-body">
                    @forelse($destinations as $destination)
                        <div class="d-flex justify-content-between align-items-center border-bottom py-3"><span class="fw-semibold"><i class="bi bi-geo-alt text-danger me-2"></i>{{ $destination['label'] }}</span><span class="badge rounded-pill text-bg-light border">{{ $destination['count'] }} perjalanan</span></div>
                    @empty
                        <x-ui.empty-state icon="geo-alt" title="Belum ada tujuan" description="Data tujuan akan muncul setelah perjalanan dibuat." />
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <x-ui.recap-tabs :recaps="$recaps" id-prefix="head-recap" />

    <div class="card">
        <div class="card-header bg-white"><h2 class="section-title"><span class="section-title-icon"><i class="bi bi-clock-history"></i></span> Perjalanan Terbaru</h2></div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>No. SPT</th><th>Pegawai</th><th>Tujuan</th><th>Tanggal</th><th>Status</th><th>Estimasi</th><th>Realisasi</th></tr></thead>
                <tbody>
                @forelse($travels as $travel)
                    <tr><td class="fw-semibold text-identity">{{ $travel->sptOperationalReference() }}</td><td>{{ $travel->pegawai?->nama_lengkap ?? '-' }}</td><td><i class="bi bi-geo-alt-fill text-danger"></i> {{ $travel->kota_tujuan }}</td><td>{{ $travel->tgl_berangkat->format('d/m/Y') }}</td><td><x-ui.status-pill :status="$travel->status" /></td><td>Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}</td><td class="fw-semibold">Rp {{ number_format((float) $travel->total_cair, 0, ',', '.') }}</td></tr>
                @empty
                    <tr><td colspan="7"><x-ui.empty-state icon="calendar-x" title="Belum ada perjalanan pada tahun ini" description="Pilih tahun lain atau tunggu data perjalanan terbaru." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($travels->hasPages())<div class="card-footer bg-white">{{ $travels->links() }}</div>@endif
    </div>
@endsection
