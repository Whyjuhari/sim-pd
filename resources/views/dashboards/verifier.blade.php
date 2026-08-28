@extends('layouts.app')
@section('title', 'Verifikasi Realisasi - SIM-PD')
@section('brand', 'Verifikasi Realisasi')
@section('page-subtitle', 'Prioritaskan pengajuan baru, periksa nilai realisasi, dan pantau riwayat pencairan.')
@section('page-actions')
    <form method="GET" class="input-group" style="min-width:min(100%, 350px)">
        <label for="q" class="visually-hidden">Cari pengajuan</label>
        <input id="q" name="q" value="{{ $search }}" class="form-control" placeholder="Pegawai, SPT, atau tujuan">
        <button class="btn btn-outline-primary"><i class="bi bi-search"></i> Cari</button>
    </form>
@endsection

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Menunggu Verifikasi" :value="$pendingTravels->total()" icon="hourglass-split" tone="warning" hint="Perlu diperiksa" /></div>
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Keputusan Tercatat" :value="$decisionCount" icon="clock-history" tone="success" hint="Disetujui dan dikembalikan" /></div>
        <div class="col-12 col-lg-4"><x-ui.metric-card label="Fokus Hari Ini" :value="$pendingTravels->total() > 0 ? 'Pengajuan Baru' : 'Semua Tertangani'" :icon="$pendingTravels->total() > 0 ? 'clipboard2-check-fill' : 'shield-check'" :tone="$pendingTravels->total() > 0 ? 'primary' : 'teal'" hint="Bekerja dari antrean teratas" /></div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-hourglass-split"></i></span> Pengajuan Baru</h2>
            <span class="badge rounded-pill text-bg-warning">{{ $pendingTravels->total() }} menunggu</span>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead><tr><th>No. SPT</th><th>Pegawai</th><th>Tujuan</th><th>Nilai Diajukan</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse($pendingTravels as $travel)
                    <tr>
                        <td class="fw-semibold text-identity">{{ $travel->no_spt }}</td>
                        <td><strong>{{ $travel->pegawai->nama_lengkap }}</strong></td>
                        <td><i class="bi bi-geo-alt-fill text-danger"></i> {{ $travel->kota_tujuan }}</td>
                        <td><div class="small"><span class="text-muted">Hotel</span><br><strong>Rp {{ number_format((float) $travel->biaya_hotel_real, 0, ',', '.') }}</strong></div><div class="small mt-1"><span class="text-muted">Transportasi</span><br><strong>Rp {{ number_format((float) $travel->biaya_tiket_real, 0, ',', '.') }}</strong></div></td>
                        <td><a href="{{ route('verifications.show', ['id' => $travel->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-check-fill"></i> Periksa Pengajuan</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="check2-circle" title="Tidak ada pengajuan baru" description="Semua realisasi yang masuk sudah ditangani." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($pendingTravels->hasPages())<div class="card-footer bg-white">{{ $pendingTravels->links() }}</div>@endif
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-clock-history"></i></span> Keputusan Terbaru</h2>
            <a href="{{ route('verifications.history') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-ul"></i> Lihat Semua</a>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead><tr><th>Waktu</th><th>No. SPT</th><th>Pegawai</th><th>Keputusan</th><th>Verifikator</th></tr></thead>
                <tbody>
                @forelse($recentDecisions as $history)
                    @php($travel = $history->perjalananDinas)
                    <tr>
                        <td>{{ $history->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        <td class="fw-semibold">{{ $travel?->no_spt ?? '-' }}</td>
                        <td>{{ $travel?->pegawai?->nama_lengkap ?? '-' }}</td>
                        <td><x-ui.status-pill :status="$history->to_status" /></td>
                        <td>{{ $history->actor?->nama_lengkap ?? 'Sistem' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty-state icon="archive" title="Belum ada keputusan" description="Keputusan persetujuan atau revisi akan tersimpan di sini." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
