@extends('layouts.app')
@section('title', 'Manajemen Role Lain - SIM-PD')
@section('brand', 'Manajemen Role Khusus')
@section('page-subtitle', 'Kelola akun administrator dan role operasional yang menjalankan proses SIM-PD.')
@section('page-actions')
    <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="bi bi-person-plus-fill"></i> Tambah Pengguna</a>
@endsection
@section('content')
<div class="card shadow-sm rounded-4"><div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h4>Pengguna dengan Role Khusus</h4><p class="text-muted mb-0">Admin dan peran operasional selain pegawai biasa.</p></div><form method="GET" class="input-group" style="max-width:320px" data-live-filter="employee-roles"><label for="role-search" class="visually-hidden">Cari pengguna</label><input id="role-search" name="q" value="{{ $search }}" class="form-control" placeholder="Nama, username, role"><button class="btn btn-outline-primary live-filter-submit"><i class="bi bi-search"></i></button></form></div>
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nama</th><th>Username</th><th>NIP</th><th>Jabatan</th><th>Role</th><th></th></tr></thead><tbody>
    @forelse($users as $user)
        <tr><td><div class="d-flex align-items-center gap-2"><img src="{{ $user->photoUrl() }}" class="avatar-sm" alt="Foto {{ $user->nama_lengkap }}"><strong>{{ $user->nama_lengkap }}</strong></div></td><td>{{ $user->username }}</td><td>{{ $user->nip ?: '-' }}</td><td>{{ $user->jabatan ?: '-' }}</td><td><span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle">{{ ['admin' => 'Administrator', 'officer' => 'Pengelola SPT', 'program' => 'Program & Anggaran', 'verifikator' => 'Verifikator', 'head' => 'Pimpinan'][$user->role] ?? ucfirst($user->role) }}</span></td><td><a href="{{ route('employees.edit', ['id' => $user->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a></td></tr>
    @empty<tr><td colspan="6"><x-ui.empty-state icon="person-badge" title="Belum ada role operasional" description="Tambahkan pengguna untuk menjalankan proses SIM-PD." /></td></tr>@endforelse
    </tbody></table></div>
    @if($users->hasPages()){{ $users->links() }}@endif
</div></div>
@endsection
