@extends('layouts.app')
@section('title', 'Dashboard Officer - SIM-PD')
@section('brand', 'Pengelolaan SPT')
@section('page-subtitle', 'Buat, cari, pantau, dan cetak Surat Perintah Tugas kolektif dengan lebih cepat.')
@section('page-actions')
    <a href="{{ route('travel-orders.create') }}" class="btn btn-identity">
        <i class="bi bi-plus-circle-fill"></i> Buat SPT Baru</a>
@endsection

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Total Surat Tugas" :value="$totalSpt" icon="files"
                tone="primary" hint="Seluruh SPT kolektif" /></div>
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Hasil Ditampilkan" :value="$sptGroups->total()"
                icon="funnel-fill" tone="teal" hint="Sesuai pencarian saat ini" /></div>
        <div class="col-12 col-lg-4">
            <div class="metric-card metric-card-warning h-100">
                <div class="metric-card-body">
                    <div class="metric-card-copy"><span class="metric-card-label">Aksi Utama</span><strong
                            class="metric-card-value fs-5">SPT</strong><span class="metric-card-hint">Satu surat
                            untuk beberapa pegawai</span></div>
                    <span class="metric-card-icon"><i class="bi bi-people-fill"></i></span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <div>
                <h2 class="section-title"><span class="section-title-icon"><i
                            class="bi bi-file-earmark-text-fill"></i></span> Daftar Surat Perintah Tugas</h2>
                <small class="text-muted">{{ $sptGroups->firstItem() ?? 0 }}–{{ $sptGroups->lastItem() ?? 0 }} dari
                    {{ $sptGroups->total() }} hasil</small>
            </div>
            <button class="btn btn-outline-primary filter-toggle" type="button" data-bs-toggle="collapse"
                data-bs-target="#officerFilters" aria-expanded="false" aria-controls="officerFilters">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>

        <div class="collapse filter-collapse" id="officerFilters">
            <div class="card-body border-bottom bg-light-subtle">
                <form method="GET" action="{{ route('dashboard.officer') }}" class="row g-3 align-items-end">
                    <div class="col-xl-5">
                        <label for="search" class="form-label small fw-semibold">Cari SPT</label>
                        <div class="input-group"><span class="input-group-text bg-white"><i
                                    class="bi bi-search"></i></span><input type="text" id="search" name="q"
                                value="{{ $filters['q'] }}" class="form-control"
                                placeholder="Nomor SPT, pegawai, memo, tujuan..."></div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label for="status" class="form-label small fw-semibold">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Semua Status</option>
                            @foreach ($statusOptions as $statusValue => $statusLabel)
                                <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>{{ $statusLabel }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 col-xl-2">
                        <label for="destination" class="form-label small fw-semibold">Kota Tujuan</label>
                        <select id="destination" name="destination" class="form-select">
                            <option value="">Semua Kota</option>
                            @foreach ($destinationOptions as $destination)
                                <option value="{{ $destination }}" @selected($filters['destination'] === $destination)>{{ $destination }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-xl-2 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill"><i
                                class="bi bi-check2"></i> Terapkan</button><a href="{{ route('dashboard.officer') }}"
                            class="btn btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i
                                class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
                @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['destination'] !== '')
                    <div class="small text-primary mt-3"><i class="bi bi-funnel-fill"></i> Filter sedang aktif</div>
                @endif
            </div>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead>
                    <tr>
                        <th>No. SPT</th>
                        <th>Pegawai</th>
                        <th>Tujuan & Tanggal</th>
                        <th>Status Anggota</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sptGroups as $group)
                        @php($travel = $group['travel'])
                        @php($previewId = 'officer-spt-preview-' . $travel->id)
                        @php($safeSptNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->no_spt) ?: 'SPT')
                        <tr>
                            <td class="fw-bold text-identity">{{ $travel->no_spt }}</td>
                            <td>
                                <ol class="mb-1 ps-3">
                                    @foreach ($group['employees'] as $employee)
                                        <li>{{ $employee->nama_lengkap }}</li>
                                    @endforeach
                                </ol>
                                <small class="text-muted">{{ $group['employees']->count() }} pegawai dalam satu Surat
                                    Tugas</small>
                            </td>
                            <td>
                                <div class="fw-semibold"><i class="bi bi-geo-alt-fill text-danger"></i>
                                    {{ $travel->kota_tujuan }}</div><small
                                    class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}
                                    · {{ $travel->lama_hari }} hari</small>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach ($group['travels'] as $memberTravel)
                                        <x-ui.status-pill :status="$memberTravel->status" />
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2"><a
                                        href="{{ route('travel-orders.show', ['sptGroupId' => $travel->spt_group_id]) }}"
                                        class="d-flex justify-content-center align-items-center btn btn-sm btn-outline-primary"><i
                                            class="bi bi-eye"></i> Detail</a><button type="button"
                                        class="btn btn-sm btn-primary" data-document-preview-trigger
                                        data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                                        data-document-label="Surat Perintah Tugas"
                                        data-document-filename="SPT_{{ $safeSptNumber }}.pdf"
                                        aria-controls="{{ $previewId }}" aria-expanded="false"><i
                                            class="bi bi-printer"></i> Cetak</button></div>
                            </td>
                        </tr>
                        <x-ui.document-preview :id="$previewId" :document-number="$travel->no_spt" />
                    @empty
                        <tr>
                            <td colspan="5"><x-ui.empty-state icon="search" title="Surat tugas tidak ditemukan"
                                    description="Ubah pencarian atau reset filter untuk melihat data lainnya.">
                                    @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['destination'] !== '')
                                        <a href="{{ route('dashboard.officer') }}"
                                            class="btn btn-outline-primary btn-sm">Reset pencarian</a>
                                    @endif
                                </x-ui.empty-state></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sptGroups->hasPages())
            <div class="card-footer bg-white">{{ $sptGroups->links() }}</div>
        @endif
    </div>
@endsection
