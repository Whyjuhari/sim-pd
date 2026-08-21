@extends('layouts.app')
@section('title', 'Realisasi Biaya - SIM-PD')
@section('brand', $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED ? 'Perbaiki Realisasi' : 'Realisasi Biaya')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="workflow-steps mb-4" aria-label="Tahapan perjalanan dinas">
            <span class="done">1. SPT</span><span class="done">2. Laporan</span>
            <span class="active">3. Realisasi</span><span>4. Verifikasi</span><span>5. Selesai</span>
        </div>

        @if($travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED)
            <div class="alert alert-danger">
                <h6 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Realisasi perlu diperbaiki</h6>
                <p class="mb-0">{{ $travel->catatan_verifikator ?: 'Silakan periksa kembali nominal realisasi.' }}</p>
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-1">{{ $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED ? 'Perbaiki Realisasi Biaya' : 'Laporkan Realisasi Biaya' }}</h5>
                <small>SPT {{ $travel->no_spt }} · {{ $travel->kota_tujuan }}</small>
            </div>
            <div class="card-body p-4">
                <p class="text-muted">Masukkan nilai sesuai bukti asli. Setelah dikirim, data akan diperiksa verifikator.</p>
                <form action="{{ route('realizations.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="id" value="{{ $travel->id }}">

                    <div class="mb-3">
                        <label for="biaya_hotel" class="form-label fw-semibold">Biaya Hotel</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input id="biaya_hotel" type="number" name="biaya_hotel" min="0" step="1"
                                value="{{ old('biaya_hotel', (float) $travel->biaya_hotel_real) }}"
                                class="form-control @error('biaya_hotel') is-invalid @enderror" required>
                        </div>
                        @error('biaya_hotel')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="biaya_tiket" class="form-label fw-semibold">Biaya Tiket/Transportasi</label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input id="biaya_tiket" type="number" name="biaya_tiket" min="0" step="1"
                                value="{{ old('biaya_tiket', (float) $travel->biaya_tiket_real) }}"
                                class="form-control @error('biaya_tiket') is-invalid @enderror" required>
                        </div>
                        @error('biaya_tiket')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <a href="{{ route('dashboard.user') }}" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Kembali ke Halaman Utama
                        </a>
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Kirim realisasi ini kepada verifikator?')">
                            <i class="bi bi-send"></i> {{ $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED ? 'Kirim Ulang' : 'Kirim Realisasi' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
