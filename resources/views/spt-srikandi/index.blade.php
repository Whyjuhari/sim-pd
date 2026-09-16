@extends('layouts.app')
@section('title', 'Proses SPT - SIM-PD')
@section('brand', 'Surat Perintah Tugas')
@section('page-actions')
    <a href="{{ route('travel-orders.create') }}" class="btn btn-identity">
        <i class="bi bi-plus-circle-fill"></i> Buat Draft SPT</a>
@endsection

@section('content')
    <div class="officer-process-list">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <form method="GET" class="row g-2 align-items-end" data-live-filter="officer-spt-process">
                    <div class="col-12 col-md">
                        <label for="q" class="form-label">Cari SPT</label>
                        <input id="q" name="q" class="form-control" value="{{ $filters['q'] }}"
                            placeholder="Nomor Naskah, kode SPT, nama/NIP pegawai, atau tujuan">
                    </div>
                    <div class="col-8 col-md-4 col-xl-3">
                        <label for="status" class="form-label">Status Surat</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Semua status</option>
                            @foreach (\App\Models\SptSrikandiWorkflow::statusLabels() as $value => $label)
                                @if ($value !== \App\Models\SptSrikandiWorkflow::STATUS_PUBLISHED)
                                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}
                                    </option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4 col-md-auto d-grid">
                        @if ($filters['q'] !== '' || $filters['status'] !== '')
                            <a class="btn btn-outline-secondary" href="{{ route('spt-srikandi.index') }}"
                                data-live-filter-reset>Reset</a>
                        @endif
                    </div>
                </form>
                <p class="small text-muted mb-0 mt-3">{{ $workflows->total() }} surat yang masih diurus.</p>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-hover align-middle officer-records">
                    <thead>
                        <tr>
                            <th>Nomor SPT</th>
                            <th>Pegawai</th>
                            <th>Tujuan & Tanggal</th>
                            <th>Status Surat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($workflows as $index => $workflow)
                            @php
                                $travel = $workflow->travels->first();
                                $employees = $workflow->travels->pluck('pegawai.nama_lengkap')->filter();
                                $detailParams = [
                                    'sptGroupId' => $workflow->spt_group_id,
                                    'from' => 'process',
                                    'list' => request()->only(['q', 'status', 'page']),
                                ];
                            @endphp
                            <tr class="officer-record">
                                <td class="officer-record-number">
                                    {{ $travel->spt_external_number ?: $travel->no_spt }}
                                </td>

                                <td class="officer-record-employees">
                                    @foreach ($employees as $name)
                                        <span class="d-block">
                                            {{ $name }}
                                        </span>
                                    @endforeach
                                </td>
                                <td class="officer-record-travel">
                                    <div class="officer-record-value">
                                        <div class="fw-semibold">{{ $travel?->kota_tujuan ?? '-' }}</div>
                                        <small
                                            class="text-muted">{{ $travel?->tgl_berangkat?->format('d/m/Y') }}–{{ $travel?->tgl_kembali?->format('d/m/Y') }}</small>
                                    </div>
                                </td>
                                <td class="officer-record-status">
                                    <div class="officer-record-value">
                                        <x-ui.spt-process-status :workflow="$workflow" />
                                        @if ($workflow->status === \App\Models\SptSrikandiWorkflow::STATUS_WAITING)
                                            <small class="d-block text-muted mt-1">Dikirim
                                                {{ $workflow->submitted_at?->format('d/m/Y') ?? '-' }}</small>
                                        @elseif ($workflow->status === \App\Models\SptSrikandiWorkflow::STATUS_PUBLISHED)
                                            <small class="d-block text-muted mt-1">Dibagikan
                                                {{ $workflow->published_at?->format('d/m/Y') ?? '-' }}</small>
                                        @endif
                                    </div>
                                </td>
                                <td class="officer-record-actions">
                                    <div class="officer-record-value">
                                        <a class="btn btn-primary btn-kelola"
                                            href="{{ route('travel-orders.show', $detailParams) }}">
                                            <i class="bi bi-folder mr-2"></i>
                                            <span class="mx-2">
                                                Kelola SPT
                                            </span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty-state icon="clipboard-check" :title="$filters['q'] !== '' || $filters['status'] !== '' ? 'SPT tidak ditemukan' : 'Tidak ada SPT yang masih diurus'"
                                        description="SPT yang sudah dibagikan dapat dilihat di Dashboard." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($workflows->hasPages())
                <div class="card-footer bg-white">{{ $workflows->links() }}</div>
            @endif
        </div>
    </div>
@endsection
