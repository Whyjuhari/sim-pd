@extends('layouts.app')
@section('title', 'Manajemen Role Lain - SIM-PD')
@section('brand', 'Manajemen Role Khusus')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3"><a href="{{ route('dashboard.admin') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a><a href="{{ route('employees.create') }}" class="btn btn-identity">Tambah</a></div>
<div class="card shadow-sm rounded-4"><div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h4>Pengguna dengan Role Khusus</h4><p class="text-muted mb-0">Admin dan peran operasional selain pegawai biasa.</p></div><form method="GET" class="input-group" style="max-width:320px"><label for="role-search" class="visually-hidden">Cari pengguna</label><input id="role-search" name="q" value="{{ $search }}" class="form-control" placeholder="Nama, username, role"><button class="btn btn-outline-primary"><i class="bi bi-search"></i></button></form></div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nama</th><th>Username</th><th>NIP</th><th>Jabatan</th><th>Role</th><th></th></tr></thead><tbody>
    @forelse($users as $user)
        <tr><td>{{ $user->nama_lengkap }}</td><td>{{ $user->username }}</td><td>{{ $user->nip }}</td><td>{{ $user->jabatan }}</td><td><span class="badge bg-secondary">{{ ucfirst($user->role) }}</span></td><td><a href="{{ route('employees.edit', ['id' => $user->id]) }}" class="btn btn-sm btn-outline-primary">Edit</a></td></tr>
    @empty<tr><td colspan="6" class="text-center text-muted py-4">Belum ada data.</td></tr>@endforelse
    </tbody></table></div>
    @if($users->hasPages()){{ $users->links() }}@endif
</div></div>
@endsection
