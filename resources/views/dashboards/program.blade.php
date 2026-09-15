@extends('layouts.app')
@section('title', 'Monitoring Anggaran - SIM-PD')
@section('brand', 'Monitoring Anggaran')
@section('page-subtitle',
    'Bandingkan estimasi, realisasi, dan selisih perjalanan dinas berdasarkan periode dan
    tujuan.')
@section('page-actions')
    <div class="d-flex flex-wrap gap-2">
        {{-- <div class="d-flex justify-content-between gap-4">
            <a href="{{ route('program.recap.export', ['format' => 'xlsx', ...array_filter($filters)]) }}"
                class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i>Excel</a>
            <a href="{{ route('program.recap.export', ['format' => 'pdf', ...array_filter($filters)]) }}"
                class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
        </div> --}}
        <a href="{{ route('program.budget.edit') }}" class="btn btn-primary"><i class="bi bi-sliders2-vertical"></i> Master
            Anggaran</a>
    </div>
@endsection

@section('content')
    @php
        $difference = $stats['estimate'] - $stats['realized'];
        $realizationRate =
            $stats['estimate'] > 0 ? min(100, round(($stats['realized'] / $stats['estimate']) * 100)) : 0;
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3"><x-ui.metric-card label="Perjalanan" :value="$stats['count']" icon="briefcase-fill"
                tone="primary" hint="Sesuai filter aktif" /></div>
        <div class="col-12 col-sm-6 col-xl-3"><x-ui.metric-card label="Total Estimasi" :value="'Rp ' . number_format($stats['estimate'], 0, ',', '.')"
                icon="calculator-fill" tone="info" hint="Rencana anggaran" /></div>
        <div class="col-12 col-sm-6 col-xl-3"><x-ui.metric-card label="Total Realisasi" :value="'Rp ' . number_format($stats['realized'], 0, ',', '.')" icon="cash-stack"
                tone="success" hint="Telah disetujui" /></div>
        <div class="col-12 col-sm-6 col-xl-3"><x-ui.metric-card label="Selisih" :value="'Rp ' . number_format($difference, 0, ',', '.')" icon="bar-chart-fill"
                tone="warning" hint="Estimasi dikurangi realisasi" /></div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-end gap-3 mb-2">
                <div><span class="small text-muted text-uppercase fw-bold">Tingkat realisasi anggaran</span>
                    <div class="fw-bold fs-5">{{ $realizationRate }}%</div>
                </div><span class="small text-muted">Dari total estimasi sesuai filter</span>
            </div>
            <div class="budget-progress" role="progressbar" aria-label="Tingkat realisasi"
                aria-valuenow="{{ $realizationRate }}" aria-valuemin="0" aria-valuemax="100">
                <div class="budget-progress-bar" style="width: {{ $realizationRate }}%"></div>
            </div>
        </div>
    </div>

    <x-ui.pmk-compliance :summary="$pmkCompliance" />

    <x-ui.recap-tabs :recaps="$recaps" id-prefix="program-recap" />

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-table"></i></span> Rincian RAB dan
                Realisasi</h2>
            <button class="btn btn-outline-primary filter-toggle" type="button" data-bs-toggle="collapse"
                data-bs-target="#programFilters" aria-expanded="false" aria-controls="programFilters"><i
                    class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="collapse filter-collapse" id="programFilters">
            <div class="card-body border-bottom bg-light-subtle">
                <form method="GET" class="row g-3 align-items-end" data-live-filter="program-dashboard">
                    <div class="col-md-6 col-xl-2"><label for="from" class="form-label small">Dari tanggal</label><input
                            id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                            class="form-control"></div>
                    <div class="col-md-6 col-xl-2"><label for="to" class="form-label small">Sampai
                            tanggal</label><input id="to" type="date" name="to"
                            value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
                    <div class="col-md-6 col-xl-2"><label for="destination" class="form-label small">Tujuan</label><select
                            id="destination" name="destination" class="form-select">
                            <option value="">Semua tujuan</option>
                            @foreach ($destinations as $destination)
                                <option @selected(($filters['destination'] ?? '') === $destination)>{{ $destination }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2"><label for="account" class="form-label small">MAK</label><select
                            id="account" name="account" class="form-select">
                            <option value="">Semua MAK</option>
                            @foreach ($accounts as $account)
                                <option @selected(($filters['account'] ?? '') === $account)>{{ $account }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2"><label for="status" class="form-label small">Status</label><select
                            id="status" name="status" class="form-select">
                            <option value="">Semua status</option>
                            @foreach ([\App\Models\PerjalananDinas::STATUS_READY, \App\Models\PerjalananDinas::STATUS_PENDING, \App\Models\PerjalananDinas::STATUS_APPROVED, \App\Models\PerjalananDinas::STATUS_REJECTED] as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                    {{ \App\Support\TravelStatus::label($status) }}</option>
                            @endforeach
                        </select></div>
                    <div class="col-xl-2 d-flex gap-2"><button class="btn btn-primary flex-fill live-filter-submit">Terapkan</button><a
                            href="{{ route('dashboard.program') }}" class="btn btn-outline-secondary"
                            aria-label="Reset filter" data-live-filter-reset><i class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
            </div>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Pegawai</th>
                        <th>Tujuan</th>
                        <th>Estimasi RAB</th>
                        <th>Realisasi</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($travels as $travel)
                        <tr>
                            <td class="fw-semibold">{{ $travel->pegawai->nama_lengkap }}</td>
                            <td><i class="bi bi-geo-alt-fill text-danger"></i> {{ $travel->kota_tujuan }}<br><small
                                    class="text-muted">{{ $travel->lama_hari }} hari</small></td>
                            <td class="fw-semibold">Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}
                            </td>
                            <td>Rp {{ number_format((float) $travel->total_cair, 0, ',', '.') }}</td>
                            <td><x-ui.status-pill :status="$travel->status" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5"><x-ui.empty-state icon="funnel" title="Tidak ada data sesuai filter"
                                    description="Ubah rentang tanggal, tujuan, atau status untuk melihat data lain." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($travels->hasPages())
            <div class="card-footer bg-white">{{ $travels->links() }}</div>
        @endif
    </div>
@endsection
