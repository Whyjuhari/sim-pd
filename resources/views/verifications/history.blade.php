@extends('layouts.app')
@section('title', 'Riwayat Keputusan - SIM-PD')
@section('brand', 'Riwayat Keputusan Verifikator')


@section('content')
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label for="q" class="form-label">Pencarian</label>
                    <input id="q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                        placeholder="Pegawai, nomor SPT, atau tujuan">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="decision" class="form-label">Keputusan</label>
                    <select id="decision" name="decision" class="form-select">
                        <option value="">Semua</option>
                        <option value="{{ \App\Models\PerjalananDinas::STATUS_APPROVED }}" @selected(($filters['decision'] ?? '') === \App\Models\PerjalananDinas::STATUS_APPROVED)>
                            Disetujui</option>
                        <option value="{{ \App\Models\PerjalananDinas::STATUS_REJECTED }}" @selected(($filters['decision'] ?? '') === \App\Models\PerjalananDinas::STATUS_REJECTED)>
                            Dikembalikan</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><label for="from" class="form-label">Dari</label><input id="from"
                        type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
                <div class="col-6 col-lg-2"><label for="to" class="form-label">Sampai</label><input id="to"
                        type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
                <div class="col-6 col-lg-2 d-grid"><button class="btn btn-primary"><i class="bi bi-funnel"></i>
                        Terapkan</button></div>
                @if (array_filter($filters))
                    <div class="col-12"><a href="{{ route('verifications.history') }}" class="btn btn-link px-0"><i
                                class="bi bi-arrow-counterclockwise"></i> Reset filter</a></div>
                @endif
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-clock-history"></i></span> Riwayat
                Tercatat</h2>
            <span class="badge text-bg-primary rounded-pill">{{ $histories->total() }}</span>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>SPT & Pegawai</th>
                        <th>Keputusan</th>
                        <th>Verifikator</th>
                        <th>Catatan</th>
                        <th>Status Saat Ini</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($histories as $history)
                        @php($travel = $history->perjalananDinas)
                        <tr>
                            <td>{{ $history->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
                            <td><strong>{{ $travel?->no_spt ?? '-' }}</strong><br><small
                                    class="text-muted">{{ $travel?->pegawai?->nama_lengkap ?? 'Pegawai tidak tersedia' }} ·
                                    {{ $travel?->kota_tujuan ?? '-' }}</small></td>
                            <td><x-ui.status-pill :status="$history->to_status" /></td>
                            <td>{{ $history->actor?->nama_lengkap ?? 'Sistem/tidak tercatat' }}</td>
                            <td class="text-break">{{ $history->note ?: '-' }}</td>
                            <td>
                                @if ($travel)
                                    <x-ui.status-pill :status="$travel->status" />
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6"><x-ui.empty-state icon="clock-history" title="Riwayat tidak ditemukan"
                                    description="Tidak ada keputusan yang sesuai dengan filter." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($histories->hasPages())
            <div class="card-footer bg-white">{{ $histories->links() }}</div>
        @endif
    </div>

    @if ($legacyTravels->total() > 0)
        <div class="card">
            <div class="card-header bg-white">
                <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-archive"></i></span> Arsip Data
                    Lama</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-secondary small"><i class="bi bi-info-circle me-1"></i> Data ini mempunyai status
                    keputusan, tetapi detail transisinya belum tercatat pada sistem audit.</div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>SPT & Pegawai</th>
                                <th>Keputusan</th>
                                <th>Verifikator</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($legacyTravels as $travel)
                                <tr>
                                    <td><strong>{{ $travel->no_spt }}</strong><br><small
                                            class="text-muted">{{ $travel->pegawai?->nama_lengkap ?? '-' }} ·
                                            {{ $travel->kota_tujuan }}</small></td>
                                    <td><x-ui.status-pill :status="$travel->status" /></td>
                                    <td>{{ $travel->verifier?->nama_lengkap ?? 'Tidak tercatat' }}</td>
                                    <td>{{ $travel->verified_at?->format('d/m/Y H:i') ?? 'Tidak tercatat' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($legacyTravels->hasPages())
                <div class="card-footer bg-white">{{ $legacyTravels->links() }}</div>
            @endif
        </div>
    @endif
@endsection
