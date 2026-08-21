@extends('layouts.app')
@section('title', 'Profil ' . $employee->nama_lengkap)
@section('brand', 'Detail Pegawai')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Daftar Pegawai</a>
        <a href="{{ route('employees.edit', ['id' => $employee->id]) }}" class="btn btn-warning"><i class="bi bi-pencil"></i> Edit Data</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="row align-items-center g-4">
                <div class="col-md-2 text-center">
                    <img src="{{ $employee->photoUrl() }}" width="120" height="120" class="rounded-circle border border-4 border-primary" style="object-fit:cover" alt="Foto {{ $employee->nama_lengkap }}">
                </div>
                <div class="col-md-10">
                    <h3 class="fw-bold text-identity mb-1">{{ $employee->nama_lengkap }}</h3>
                    <p class="text-muted">NIP: {{ $employee->nip ?: '-' }}</p>
                    <div class="row g-3">
                        <div class="col-md-4"><small class="text-muted d-block">Jabatan</small><span class="fw-semibold">{{ $employee->jabatan ?: '-' }}</span></div>
                        <div class="col-md-4"><small class="text-muted d-block">Pangkat/Golongan</small><span class="fw-semibold">{{ $employee->pangkat_golongan ?: '-' }}</span></div>
                        <div class="col-md-4"><small class="text-muted d-block">Tanda Tangan</small>
                            @if ($employee->hasSignature())
                                <span class="badge bg-success">Tersedia</span>
                                <a href="{{ route('employees.signature', ['employee' => $employee->id]) }}" target="_blank" class="btn btn-sm btn-link">Lihat</a>
                            @else
                                <span class="badge bg-warning text-dark">Belum tersedia</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-0"><i class="bi bi-clock-history"></i> Riwayat Perjalanan Dinas</h5>
                <small class="text-muted">{{ $travels->total() }} perjalanan sesuai filter</small>
            </div>
            <form method="GET" action="{{ route('employees.show') }}" class="d-flex gap-2">
                <input type="hidden" name="id" value="{{ $employee->id }}">
                <label for="status" class="visually-hidden">Filter status perjalanan</label>
                <select id="status" name="status" class="form-select" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    @foreach([\App\Models\PerjalananDinas::STATUS_READY, \App\Models\PerjalananDinas::STATUS_PENDING, \App\Models\PerjalananDinas::STATUS_REJECTED, \App\Models\PerjalananDinas::STATUS_APPROVED] as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ \App\Support\TravelStatus::label($option) }} ({{ $statusCounts[$option] ?? 0 }})</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead class="table-light"><tr><th>No. SPT</th><th>Tujuan</th><th>Tanggal</th><th>Status</th><th>Nilai</th><th>Dokumen</th></tr></thead>
                <tbody>
                    @forelse($travels as $travel)
                        <tr>
                            <td class="fw-semibold">{{ $travel->no_spt }}</td>
                            <td>{{ $travel->kota_tujuan }}</td>
                            <td>{{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}</td>
                            <td><span class="badge status-{{ $travel->status }}">{{ \App\Support\TravelStatus::label($travel->status) }}</span></td>
                            <td>Rp {{ number_format((float) ($travel->status === \App\Models\PerjalananDinas::STATUS_APPROVED ? $travel->total_cair : $travel->estimasi_biaya), 0, ',', '.') }}</td>
                            <td>
                                @can('printTravelDocument', $travel)
                                    <a target="_blank" href="{{ route('documents.perjadin', ['id' => $travel->id]) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer"></i> Cetak</a>
                                @else
                                    <span class="text-muted small">Belum tersedia</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-5">Tidak ada perjalanan sesuai filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($travels->hasPages())<div class="card-footer bg-white">{{ $travels->links() }}</div>@endif
    </div>
@endsection
