@extends('layouts.app')
@section('title', 'Verifikasi Realisasi - SIM-PD')
@section('brand', 'Verifikasi Realisasi')

@section('content')
<div class="row justify-content-center">
    <div class="col-xl-9">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-1">Verifikasi Realisasi {{ $travel->pegawai->nama_lengkap }}</h5>
                <small class="text-muted">SPT {{ $travel->no_spt }} · {{ $travel->kota_tujuan }} · {{ $travel->lama_hari }} hari</small>
            </div>
            <div class="card-body p-4">
                <div class="alert alert-info">
                    Batas hotel Rp {{ number_format($recommendation['hotel_per_day'], 0, ',', '.') }} per hari,
                    sehingga batas perjalanan ini Rp {{ number_format($recommendation['hotel_limit'], 0, ',', '.') }}.
                    Batas transportasi Rp {{ number_format($recommendation['transport_limit'], 0, ',', '.') }}.
                </div>

                <form action="{{ route('verifications.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="id" value="{{ $travel->id }}">
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Hotel yang Diajukan Pegawai</label>
                            <input class="form-control bg-light" value="Rp {{ number_format((float) $travel->biaya_hotel_real, 0, ',', '.') }}" readonly>
                            <label for="hotel_approved" class="form-label fw-semibold text-success mt-2">Hotel Disetujui</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input id="hotel_approved" type="number" name="hotel_approved" min="0"
                                    max="{{ $recommendation['hotel_limit'] }}" step="1"
                                    value="{{ old('hotel_approved', $recommendation['hotel_recommended']) }}"
                                    class="form-control @error('hotel_approved') is-invalid @enderror">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Transportasi yang Diajukan Pegawai</label>
                            <input class="form-control bg-light" value="Rp {{ number_format((float) $travel->biaya_tiket_real, 0, ',', '.') }}" readonly>
                            <label for="tiket_approved" class="form-label fw-semibold text-success mt-2">Transportasi Disetujui</label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input id="tiket_approved" type="number" name="tiket_approved" min="0"
                                    max="{{ $recommendation['transport_limit'] }}" step="1"
                                    value="{{ old('tiket_approved', $recommendation['transport_recommended']) }}"
                                    class="form-control @error('tiket_approved') is-invalid @enderror">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Uang Harian Otomatis</label>
                        <input class="form-control bg-light" value="Rp {{ number_format($recommendation['daily_allowance_total'], 0, ',', '.') }}" readonly>
                    </div>
                    <div class="mb-4">
                        <label for="catatan" class="form-label fw-semibold">Catatan Verifikator</label>
                        <textarea id="catatan" name="catatan" class="form-control @error('catatan') is-invalid @enderror" rows="3" maxlength="5000"
                            placeholder="Wajib diisi jika realisasi dikembalikan untuk revisi">{{ old('catatan') }}</textarea>
                        @error('catatan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <a href="{{ route('dashboard.verifier') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
                        <div class="d-flex flex-wrap gap-2">
                            <button name="action" value="reject" class="btn btn-outline-danger"
                                onclick="return confirm('Kembalikan realisasi ini kepada pegawai untuk diperbaiki?')">
                                <i class="bi bi-arrow-counterclockwise"></i> Kembalikan untuk Revisi
                            </button>
                            <button name="action" value="approve" class="btn btn-success"
                                onclick="return confirm('Setujui realisasi dan nilai pencairan ini?')">
                                <i class="bi bi-check-circle"></i> Setujui Realisasi
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        @if($travel->statusHistories->isNotEmpty())
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-white fw-semibold">Riwayat Keputusan</div>
                <div class="list-group list-group-flush">
                    @foreach($travel->statusHistories as $history)
                        <div class="list-group-item py-3">
                            <div class="d-flex justify-content-between flex-wrap gap-2">
                                <span><strong>{{ \App\Support\TravelStatus::label($history->from_status) }}</strong> → <strong>{{ \App\Support\TravelStatus::label($history->to_status) }}</strong></span>
                                <small class="text-muted">{{ $history->created_at?->format('d/m/Y H:i') }}</small>
                            </div>
                            <small class="text-muted">Oleh {{ $history->actor?->nama_lengkap ?? 'Sistem' }}</small>
                            @if($history->note)<p class="mb-0 mt-1">{{ $history->note }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
