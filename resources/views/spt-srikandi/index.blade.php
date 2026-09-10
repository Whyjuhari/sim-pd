@extends('layouts.app')

@section('title', 'SPT Srikandi - SIM-PD')
@section('brand', 'Surat Perintah Tugas')
@section('page-actions')
    <a href="{{ route('travel-orders.create') }}" class="btn btn-identity">
        <i class="bi bi-plus-circle-fill"></i> Buat Draft Surat</a>
@endsection


@section('content')
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md">
                    <label for="q" class="form-label">Cari SPT</label>
                    <input id="q" name="q" class="form-control" value="{{ $filters['q'] }}"
                        placeholder="Pegawai, atau tujuan">
                </div>
                <div class="col-8 col-md-4 col-xl-3">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">Semua status</option>
                        @foreach ([
            \App\Models\SptSrikandiWorkflow::STATUS_DRAFT => 'Draft',
            \App\Models\SptSrikandiWorkflow::STATUS_WAITING => 'Menunggu Srikandi',
            \App\Models\SptSrikandiWorkflow::STATUS_UPLOADED => 'Siap Diterbitkan',
            \App\Models\SptSrikandiWorkflow::STATUS_PUBLISHED => 'Sudah Diterbitkan',
        ] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-auto d-grid">
                    <button class="btn btn-outline-primary"><i class="bi bi-search"></i> Cari</button>
                </div>
            </form>
        </div>

        <div class="card-body table-responsive">
            <table class="table table-hover align-middle responsive-records">
                <thead>
                    <tr>
                        <th>Perjalanan</th>
                        <th>Pegawai</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workflows as $workflow)
                        @php
                            $travel = $workflow->travels->first();
                            $employees = $workflow->travels->pluck('pegawai.nama_lengkap')->filter();
                        @endphp
                        <tr>
                            <td data-label="Perjalanan">
                                <span class="fw-semibold">
                                    {{ $travel?->kota_tujuan ?? '-' }}
                                    @if ($travel?->tgl_berangkat && $travel?->tgl_kembali)
                                        <small class="d-block text-muted">
                                            {{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}
                                        </small>
                                    @endif
                                </span>
                            </td>
                            <td data-label="Pegawai">
                                {{ $employees->take(2)->join(', ') ?: '-' }}
                                @if ($employees->count() > 2)
                                    <small class="d-block text-muted">+{{ $employees->count() - 2 }} pegawai</small>
                                @endif
                            </td>
                            <td data-label="Status"><span class="badge text-bg-light border">{{ $workflow->label() }}</span>
                            </td>
                            <td data-label="Aksi">
                                <a class="btn btn-sm btn-primary d-flex align-items-center justify-content-center gap-1"
                                    href="{{ route('spt-srikandi.show', ['sptGroupId' => $workflow->spt_group_id]) }}">
                                    <i class="bi bi-folder2-open"></i>
                                    <span>Kelola</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state icon="send" title="Belum ada alur Srikandi"
                                    description="SPT baru dengan mode Parameter Srikandi akan muncul di sini." />
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
@endsection
