@extends('layouts.app')
@section('title', 'Verifikasi Realisasi - SIM-PD')
@section('brand', 'Verifikasi Realisasi')
@section('page-subtitle', 'Periksa setiap rincian dan bukti sebelum menetapkan nilai pencairan.')

@section('content')
@php
    $approvedSubtotal = array_sum($recommendedApprovals);
    $systemDisbursementTotal = $approvedSubtotal + (float) $recommendation['daily_allowance_total'];
    $usesGroundTransportPmk = ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground';
    $usesAirTransportPmk = ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_air';
    $usesTransportPmk = $usesGroundTransportPmk || $usesAirTransportPmk;
@endphp
<div class="row justify-content-center">
    <div class="col-xl-10">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-1">Verifikasi Realisasi {{ $travel->pegawai->nama_lengkap }}</h5>
                <small class="text-muted">SPT {{ $travel->sptOperationalReference() }} · {{ $travel->kota_tujuan }} · {{ $travel->lama_hari }} hari · {{ $travel->angkutan }}</small>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4"><div class="verification-limit-card"><span>Batas Hotel</span><strong>Rp {{ number_format($recommendation['hotel_limit'], 0, ',', '.') }}</strong><small>{{ $recommendation['hotel_nights'] > 0 ? 'Rp '.number_format($recommendation['hotel_per_day'], 0, ',', '.').' × '.$recommendation['hotel_nights'].' malam' : 'Tidak tersedia untuk perjalanan satu hari' }}{{ $recommendation['hotel_rate_source'] === 'pmk' ? ' · PMK 32/2025' : '' }}</small></div></div>
                    <div class="col-md-4"><div class="verification-limit-card"><span>{{ $usesTransportPmk ? 'Patokan Transportasi' : 'Batas Transportasi' }}</span><strong>Rp {{ number_format($recommendation['transport_limit'], 0, ',', '.') }}</strong><small>@if($usesGroundTransportPmk) Rp {{ number_format($recommendation['ground_transport_one_way'], 0, ',', '.') }} per arah · dapat dilampaui @elseif($usesAirTransportPmk) Terminal dan tiket dihitung per komponen · dapat dilampaui @else Mengikuti snapshot angkutan SPT @endif</small></div></div>
                    <div class="col-md-4"><div class="verification-limit-card"><span>Uang Harian Otomatis</span><strong>Rp {{ number_format($recommendation['daily_allowance_total'], 0, ',', '.') }}</strong><small>{{ $travel->lama_hari }} hari perjalanan</small></div></div>
                </div>
                <div class="alert alert-primary d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mt-3 mb-0" role="status">
                    <span><i class="bi bi-calculator me-1"></i> {{ $usesTransportPmk ? 'Nilai transportasi mengikuti klaim riil; sistem menandai perbandingannya terhadap patokan setiap komponen.' : 'Nilai pencairan dihitung otomatis dari klaim dan batas biaya SPT.' }}</span>
                    <strong class="text-nowrap">Total Rp {{ number_format($systemDisbursementTotal, 0, ',', '.') }}</strong>
                </div>
            </div>
        </div>

        <x-ui.pmk-compliance :summary="$exceptionSummary" title="Ringkasan Pengecualian" />

        <form action="{{ route('verifications.store') }}" method="POST">
            @csrf
            <input type="hidden" name="id" value="{{ $travel->id }}">

            @if($errors->has('approved'))
                <div class="alert alert-danger">{{ $errors->first('approved') }}</div>
            @endif

            @forelse($travel->rincianRealisasi->groupBy('kategori') as $category => $details)
                <section class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-{{ $category === 'hotel' ? 'building' : 'signpost-2' }}"></i></span>{{ $category === 'hotel' ? 'Penginapan' : 'Transportasi' }}</h2>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        @foreach($details as $detail)
                            <article class="verification-detail-card">
                                <div class="row g-4">
                                    <div class="col-lg-5">
                                        <div class="small text-muted mb-1">Rincian</div>
                                        <h3 class="h6 mb-2">{{ $detail->uraian }}</h3>
                                        <div class="small text-muted">Diajukan Pegawai</div>
                                        <div class="fs-5 fw-bold text-identity">Rp {{ number_format((float) $detail->nilai_diajukan, 0, ',', '.') }}</div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="small text-muted mb-2">Bukti biaya</div>
                                        @if($detail->bukti->isNotEmpty())
                                            <x-ui.evidence-list :items="$detail->bukti" />
                                        @else
                                            <p class="small text-muted mb-0">Tidak ada bukti pada rincian ini.</p>
                                        @endif
                                    </div>
                                    <div class="col-lg-3">
                                        @php
                                            $systemApproved = (float) ($recommendedApprovals[$detail->id] ?? 0);
                                            $difference = max(0, (float) $detail->nilai_diajukan - $systemApproved);
                                            $isOfficialGroundRoute = $usesGroundTransportPmk
                                                && $category === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT
                                                && in_array($detail->kode, [
                                                    \App\Models\RealisasiRincian::CODE_GROUND_OUTBOUND,
                                                    \App\Models\RealisasiRincian::CODE_GROUND_RETURN,
                                                ], true);
                                            $groundBenchmarkDifference = $isOfficialGroundRoute
                                                ? (float) $detail->nilai_diajukan - (float) $recommendation['ground_transport_one_way']
                                                : 0;
                                            $isAirDetail = $usesAirTransportPmk
                                                && $category === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT;
                                            $airBenchmark = $isAirDetail && $detail->benchmark_amount_snapshot !== null
                                                ? (float) $detail->benchmark_amount_snapshot
                                                : null;
                                            $airBenchmarkDifference = $airBenchmark === null
                                                ? null
                                                : (float) $detail->nilai_diajukan - $airBenchmark;
                                        @endphp
                                        <div class="small text-muted mb-1">Nilai Disetujui Sistem</div>
                                        <div class="fs-5 fw-bold text-success">Rp {{ number_format($systemApproved, 0, ',', '.') }}</div>
                                        @if($isOfficialGroundRoute && $groundBenchmarkDifference > 0)
                                            <div class="small text-warning-emphasis mt-1">
                                                <i class="bi bi-exclamation-circle me-1"></i>Melebihi patokan PMK Rp {{ number_format($groundBenchmarkDifference, 0, ',', '.') }}. Klaim tidak dipotong otomatis.
                                            </div>
                                        @elseif($isOfficialGroundRoute)
                                            <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Dalam patokan PMK.</div>
                                        @elseif($usesGroundTransportPmk && $category === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT)
                                            <div class="small text-warning-emphasis mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Tidak ada pasangan tarif PMK; nilai sistem Rp0.
                                            </div>
                                        @elseif($isAirDetail && $detail->benchmark_source === 'no_benchmark')
                                            <div class="small text-warning-emphasis mt-1"><i class="bi bi-info-circle me-1"></i>Tidak memiliki patokan PMK; diperiksa dari bukti.</div>
                                        @elseif($isAirDetail && $airBenchmarkDifference !== null && $airBenchmarkDifference > 0)
                                            <div class="small text-warning-emphasis mt-1"><i class="bi bi-exclamation-circle me-1"></i>Melebihi {{ $detail->benchmark_source === 'pmk' ? 'patokan PMK' : 'estimasi legacy' }} Rp {{ number_format($airBenchmarkDifference, 0, ',', '.') }}. Tidak dipotong otomatis.</div>
                                            @if($detail->overrun_reason)<div class="small mt-2"><strong>Alasan:</strong> {{ $detail->overrun_reason }}</div>@endif
                                            @if($detail->office_route_confirmed && $detail->non_private_vehicle_confirmed)<div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Dari/ke kantor dan tidak memakai kendaraan pribadi.</div>@endif
                                        @elseif($isAirDetail && $airBenchmark !== null)
                                            <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Dalam {{ $detail->benchmark_source === 'pmk' ? 'patokan PMK' : 'estimasi legacy' }} Rp {{ number_format($airBenchmark, 0, ',', '.') }}.</div>
                                        @elseif($difference > 0)
                                            <div class="small text-warning-emphasis mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Dikurangi Rp {{ number_format($difference, 0, ',', '.') }} sesuai batas kategori.
                                            </div>
                                        @else
                                            <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Sesuai nilai diajukan.</div>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="alert alert-danger">Rincian realisasi belum tersedia. Pengajuan ini tidak dapat disetujui sebelum data diperbaiki.</div>
            @endforelse

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <label for="catatan" class="form-label fw-semibold">Catatan Verifikator</label>
                    <textarea id="catatan" name="catatan" class="form-control @error('catatan') is-invalid @enderror" rows="3" maxlength="5000"
                        placeholder="Wajib diisi jika realisasi dikembalikan untuk revisi">{{ old('catatan') }}</textarea>
                    @error('catatan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-action-bar">
                <a href="{{ route('dashboard.verifier') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
                <div class="d-flex flex-wrap gap-2">
                    <button name="action" value="reject" class="btn btn-outline-danger"
                        data-sim-confirm data-sim-confirm-title="Kembalikan untuk revisi?"
                        data-sim-confirm-text="Realisasi akan dikembalikan kepada pegawai agar rincian, nominal, atau buktinya dapat diperbaiki."
                        data-sim-confirm-button="Ya, kembalikan" data-sim-confirm-tone="warning">
                        <i class="bi bi-arrow-counterclockwise"></i> Kembalikan untuk Revisi
                    </button>
                    <button name="action" value="approve" class="btn btn-success" @disabled($travel->rincianRealisasi->isEmpty())
                        data-sim-confirm data-sim-confirm-title="Setujui realisasi?"
                        data-sim-confirm-text="{{ $usesTransportPmk ? 'Nilai transportasi mengikuti klaim riil yang telah dibandingkan dengan patokan per rincian dan bukti.' : 'Nilai pencairan telah dihitung dan dikunci oleh sistem berdasarkan klaim serta batas biaya SPT.' }}"
                        data-sim-confirm-button="Ya, setujui" data-sim-confirm-tone="success">
                        <i class="bi bi-check-circle"></i> Setujui Realisasi
                    </button>
                </div>
            </div>
        </form>

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
