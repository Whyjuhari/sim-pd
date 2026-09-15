@extends('layouts.app')
@section('title', 'Dashboard Officer - SIM-PD')
@section('brand', 'Pengelolaan SPT')
@section('page-subtitle', 'SPT yang sudah tersedia bagi pegawai.')
@section('content')
    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-file-earmark-text-fill"></i></span>
                    SPT yang Sudah Tersedia</h2>
                <small class="text-muted">{{ $sptGroups->firstItem() ?? 0 }}–{{ $sptGroups->lastItem() ?? 0 }} dari
                    {{ $sptGroups->total() }} hasil</small>
            </div>
            <button class="btn btn-outline-primary filter-toggle" type="button" data-bs-toggle="collapse"
                data-bs-target="#officerFilters"
                aria-expanded="{{ collect($filters)->filter()->isNotEmpty() ? 'true' : 'false' }}"
                aria-controls="officerFilters">
                <i class="bi bi-funnel"></i> Filter
            </button>
        </div>

        <div class="collapse filter-collapse {{ collect($filters)->filter()->isNotEmpty() ? 'show' : '' }}"
            id="officerFilters">
            <div class="card-body border-bottom bg-light-subtle">
                <form method="GET" action="{{ route('dashboard.officer') }}" class="row g-3 align-items-end"
                    data-live-filter="officer-dashboard">
                    <div class="col-xl-5">
                        <label for="search" class="form-label small fw-semibold">Cari SPT</label>
                        <div class="input-group"><span class="input-group-text bg-white"><i
                                    class="bi bi-search"></i></span><input type="text" id="search" name="q"
                                value="{{ $filters['q'] }}" class="form-control"
                                placeholder="Kode/nomor SPT, pegawai, memo, tujuan..."></div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label for="status" class="form-label small fw-semibold">Perjalanan Pegawai</label>
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
                    <div class="col-xl-2 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill live-filter-submit"><i
                                class="bi bi-check2"></i> Terapkan</button><a href="{{ route('dashboard.officer') }}"
                            class="btn btn-outline-secondary" title="Reset filter" aria-label="Reset filter"
                            data-live-filter-reset><i
                                class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
                @if (collect($filters)->filter()->isNotEmpty())
                    <div class="small text-primary mt-3"><i class="bi bi-funnel-fill"></i> Filter sedang aktif</div>
                @endif
            </div>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky officer-records">
                <thead>
                    <tr>
                        <th>Kode / Nomor SPT</th>
                        <th>Pegawai</th>
                        <th>Tujuan & Tanggal</th>
                        <th>Perjalanan Pegawai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($sptGroups as $group)
                        @php($travel = $group['travel'])
                        @php($workflow = $travel->sptSrikandiWorkflow)
                        @php($detailParams = ['sptGroupId' => $travel->spt_group_id, 'from' => 'dashboard', 'list' => request()->only(['q', 'status', 'destination', 'page'])])
                        @php($previewId = 'officer-spt-preview-' . $travel->id)
                        @php($safeSptNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->sptOperationalReference()) ?: 'SPT')
                        <tr class="officer-record">
                            <td class="officer-record-number fw-bold text-identity">
                                <div class="officer-record-value">
                                    {{ $travel->sptOperationalReference() }}
                                </div>
                                @if ($travel->spt_internal_reference && $travel->sptOperationalReference() !== $travel->spt_internal_reference)
                                    <small
                                        class="d-block text-muted fw-normal mt-1">{{ $travel->spt_internal_reference }}</small>
                                @endif
                            </td>
                            <td class="officer-record-employees">
                                <div class="officer-record-value">
                                    <ol class="mb-1 ps-3">
                                        @foreach ($group['employees'] as $employee)
                                            <li>{{ $employee->nama_lengkap }}</li>
                                        @endforeach
                                    </ol>
                                    <small class="text-muted">{{ $group['employees']->count() }} pegawai dalam satu Surat
                                        Tugas</small>
                                </div>
                            </td>
                            <td class="officer-record-travel">
                                <div class="officer-record-value">
                                    <div class="fw-semibold"><i class="bi bi-geo-alt-fill text-danger"></i>
                                        {{ $travel->kota_tujuan }}</div>
                                    <small
                                        class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}
                                        · {{ $travel->lama_hari }} hari</small>
                                </div>
                            </td>
                            <td class="officer-record-status">
                                <div class="officer-record-value">
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($group['travels']->groupBy('status') as $memberStatus => $members)
                                            <span><x-ui.status-pill :status="$memberStatus" /> <small class="text-muted">{{ $members->count() }} pegawai</small></span>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                            <td class="officer-record-actions">
                                <div
                                    class="officer-record-value officer-record-actions-grid d-flex flex-wrap align-items-center gap-2">
                                    <a href="{{ route('travel-orders.show', $detailParams) }}"
                                        class="d-flex justify-content-center align-items-center btn btn-sm btn-outline-primary"><i
                                            class="bi bi-info-circle me-1"></i> Detail</a><button type="button"
                                        class="btn btn-sm btn-primary" data-document-preview-trigger
                                        data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                                        data-document-label="Surat Perintah Tugas"
                                        data-document-filename="SPT_{{ $safeSptNumber }}.pdf"
                                        aria-controls="{{ $previewId }}" aria-expanded="false"><i
                                            class="bi bi-eye me-1"></i>
                                        Lihat SPT</button>
                                </div>
                            </td>
                        </tr>
                        <x-ui.document-preview :id="$previewId" :document-number="$travel->sptOperationalReference()" />
                    @empty
                        <tr>
                            <td colspan="5"><x-ui.empty-state icon="search" title="Surat tugas tidak ditemukan"
                                    description="Surat yang masih diurus tersedia di menu Proses SPT.">
                                    @if (collect($filters)->filter()->isNotEmpty())
                                        <a href="{{ route('dashboard.officer') }}" data-live-filter-reset
                                            class="btn btn-outline-primary btn-sm">Reset pencarian</a>
                                    @endif
                                    <a href="{{ route('spt-srikandi.index') }}" class="btn btn-outline-primary btn-sm">Buka Proses SPT</a>
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
