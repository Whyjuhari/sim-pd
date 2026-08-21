@extends('layouts.app')
@section('title', 'Manajemen Pegawai - SIM-PD')
@section('brand', 'Manajemen Pegawai')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('dashboard.admin') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
    <div class="d-flex gap-2"><a href="{{ route('employees.roles') }}" class="btn btn-outline-primary">Role Lain</a><a href="{{ route('employees.create') }}" class="btn btn-success"><i class="bi bi-plus-circle"></i> Tambah Baru</a></div>
</div>
<div class="card shadow-sm">
    <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0 fw-bold text-identity">Data Pegawai Perjadin</h5>
        <form method="GET" class="input-group" style="max-width:340px">
            <label for="employee-search" class="visually-hidden">Cari pegawai</label>
            <input id="employee-search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Nama, NIP, username">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle table-actions-sticky">
            <thead class="table-light"><tr><th>No</th><th>Nama Lengkap</th><th>NIP</th><th class="text-center">Jml. Perjadin ({{ $year }})</th><th>Role</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse($employees as $employee)
                <tr>
                    <td>{{ ($employees->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="fw-bold">{{ $employee->nama_lengkap }}</td>
                    <td>{{ $employee->nip }}</td>
                    <td class="text-center"><span class="badge rounded-pill {{ $employee->jumlah_perjadin > 10 ? 'bg-danger' : ($employee->jumlah_perjadin > 5 ? 'bg-warning text-dark' : 'bg-light text-muted border') }} px-3 py-2">{{ $employee->jumlah_perjadin }} Kali</span></td>
                    <td><span class="badge bg-secondary">Pegawai</span></td>
                    <td class="d-flex gap-1">
                        <a href="{{ route('employees.show', ['id' => $employee->id]) }}" class="btn btn-primary btn-sm"><i class="bi bi-person-lines-fill"></i> Detail</a>
                        <a href="{{ route('employees.edit', ['id' => $employee->id]) }}" class="btn btn-warning btn-sm text-white"><i class="bi bi-pencil-square"></i></a>
                        <form method="post" action="{{ route('employees.destroy') }}" onsubmit="return confirm('Hapus pegawai ini?')">@csrf<input type="hidden" name="id" value="{{ $employee->id }}"><button class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada pegawai.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($employees->hasPages())<div class="card-footer bg-white">{{ $employees->links() }}</div>@endif
</div>
@endsection
