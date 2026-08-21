@extends('layouts.app')
@section('title', 'Verifikasi Realisasi - SIM-PD')
@section('brand', 'Verifikasi Realisasi')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><h4 class="mb-1">Verifikasi Realisasi</h4><p class="text-muted mb-0">Periksa pengajuan biaya dan riwayat pencairan.</p></div>
    <form method="GET" class="input-group" style="max-width:360px">
        <label for="q" class="visually-hidden">Cari pengajuan</label>
        <input id="q" name="q" value="{{ $search }}" class="form-control" placeholder="Cari pegawai, SPT, tujuan">
        <button class="btn btn-outline-primary"><i class="bi bi-search"></i> Cari</button>
    </form>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-hourglass-split text-warning"></i> Menunggu Verifikasi <span class="badge bg-warning text-dark">{{ $pendingTravels->total() }}</span></h6></div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle table-actions-sticky">
            <thead class="table-light"><tr><th>No. SPT</th><th>Pegawai</th><th>Tujuan</th><th>Nilai Diajukan</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($pendingTravels as $travel)
                <tr>
                    <td class="fw-semibold">{{ $travel->no_spt }}</td><td>{{ $travel->pegawai->nama_lengkap }}</td><td>{{ $travel->kota_tujuan }}</td>
                    <td class="small">Hotel: Rp {{ number_format((float) $travel->biaya_hotel_real, 0, ',', '.') }}<br>Transportasi: Rp {{ number_format((float) $travel->biaya_tiket_real, 0, ',', '.') }}</td>
                    <td><a href="{{ route('verifications.show', ['id' => $travel->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-check"></i> Verifikasi</a></td>
                </tr>
            @empty<tr><td colspan="5" class="text-center text-muted py-5">Tidak ada pengajuan baru.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @if($pendingTravels->hasPages())<div class="card-footer bg-white">{{ $pendingTravels->links() }}</div>@endif
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-check-circle text-success"></i> Riwayat Pencairan</h6></div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle table-actions-sticky">
            <thead class="table-light"><tr><th>No. SPT</th><th>Pegawai</th><th>Total Cair</th><th>Tanggal Lapor</th><th>Dokumen</th></tr></thead>
            <tbody>
            @forelse($approvedTravels as $travel)
                <tr><td>{{ $travel->no_spt }}</td><td>{{ $travel->pegawai->nama_lengkap }}</td><td class="fw-bold text-success">Rp {{ number_format((float) $travel->total_cair, 0, ',', '.') }}</td><td>{{ $travel->tgl_lapor?->format('d/m/Y') ?? '-' }}</td><td><a target="_blank" href="{{ route('documents.perjadin', ['id' => $travel->id]) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer"></i> Cetak</a></td></tr>
            @empty<tr><td colspan="5" class="text-center text-muted py-5">Belum ada riwayat pencairan.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    @if($approvedTravels->hasPages())<div class="card-footer bg-white">{{ $approvedTravels->links() }}</div>@endif
</div>
@endsection
