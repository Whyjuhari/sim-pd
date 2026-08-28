@extends('layouts.app')
@section('title', 'Manajemen Pegawai - SIM-PD')
@section('brand', 'Manajemen Pegawai')
@section('page-subtitle', 'Kelola identitas pegawai, kelengkapan tanda tangan, dan riwayat perjalanan dinas.')
@section('page-actions')
    <a href="{{ route('employees.roles') }}" class="btn btn-outline-primary">
        <i class="bi bi-person-badge"></i> Role Operasional</a>
    <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="bi bi-person-plus-fill"></i> Tambah Pegawai</a>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0 fw-bold text-identity">Data Pegawai Perjadin</h5>
            <form method="GET" class="input-group" style="max-width:340px">
                <label for="employee-search" class="visually-hidden">Cari pegawai</label>
                <input id="employee-search" name="q" value="{{ $search }}" class="form-control form-control-sm"
                    placeholder="Nama, NIP, username">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead class="table-light">
                    <tr>
                        <th>Pegawai</th>
                        <th>NIP</th>
                        <th class="text-center">Perjadin {{ $year }}</th>
                        <th>Tanda Tangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $employee)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2"><img src="{{ $employee->photoUrl() }}"
                                        class="avatar-sm" alt="Foto {{ $employee->nama_lengkap }}">
                                    <div>
                                        <div class="fw-bold">{{ $employee->nama_lengkap }}</div><small
                                            class="text-muted">{{ $employee->jabatan ?: 'Pegawai' }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $employee->nip }}</td>
                            <td class="text-center"><span
                                    class="badge rounded-pill {{ $employee->jumlah_perjadin > 10 ? 'bg-danger' : ($employee->jumlah_perjadin > 5 ? 'bg-warning text-dark' : 'bg-light text-muted border') }} px-3 py-2">{{ $employee->jumlah_perjadin }}
                                    Kali</span></td>
                            <td>
                                @if ($employee->hasSignature())
                                    <span
                                        class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle"><i
                                        class="bi bi-check-circle-fill"></i> Tersedia</span>@else<span
                                        class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i
                                            class="bi bi-exclamation-circle-fill"></i> Belum ada</span>
                                @endif
                            </td>
                            <td class="d-flex gap-1">

                                <a href="{{ route('employees.edit', ['id' => $employee->id]) }}"
                                    class="btn btn-warning btn-sm text-white"><i class="bi bi-pencil-square"></i></a>
                                <form method="post" action="{{ route('employees.destroy') }}"
                                    data-sim-confirm
                                    data-sim-confirm-title="Hapus pegawai?"
                                    data-sim-confirm-text="Data pegawai dan data terkait yang diizinkan sistem akan dihapus. Tindakan ini tidak dapat dibatalkan."
                                    data-sim-confirm-button="Ya, hapus pegawai"
                                    data-sim-confirm-tone="danger">@csrf<input type="hidden"
                                        name="id" value="{{ $employee->id }}"><button class="btn btn-danger btn-sm"><i
                                            class="bi bi-trash"></i></button></form>
                                <a href="{{ route('employees.show', ['id' => $employee->id]) }}"
                                    class="btn btn-primary btn-sm"><i class="bi bi-person-lines-fill"></i> Detail</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5"><x-ui.empty-state icon="people" title="Belum ada pegawai"
                                    description="Tambahkan pegawai agar dapat dibuatkan Surat Perintah Tugas." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($employees->hasPages())
            <div class="card-footer bg-white">{{ $employees->links() }}</div>
        @endif
    </div>
@endsection
