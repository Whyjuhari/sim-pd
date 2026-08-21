@extends('layouts.app')
@section('title', 'Profil dan Keamanan - SIM-PD')
@section('brand', 'Profil dan Keamanan')

@section('content')
<div class="row justify-content-center">
    <div class="col-xl-8">
        @if($employee->force_password_change)
            <div class="alert alert-warning"><i class="bi bi-shield-lock"></i> Anda masih menggunakan password awal. Ganti password sebelum melanjutkan.</div>
        @endif
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('profile.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-4">
                        <div class="col-md-4 text-center">
                            <img src="{{ $employee->photoUrl() }}" width="140" height="140" class="rounded-circle border shadow-sm mb-3" style="object-fit:cover" alt="Foto profil {{ $employee->nama_lengkap }}">
                            <label for="foto" class="form-label fw-semibold d-block">Ganti Foto</label>
                            <input id="foto" type="file" name="foto" class="form-control form-control-sm" accept="image/jpeg,image/png">
                            <small class="text-muted">JPG/PNG, maksimal 2 MB.</small>
                        </div>
                        <div class="col-md-8">
                            <div class="alert alert-info small">NIP, jabatan, username, dan role hanya dapat diubah Admin.</div>
                            <div class="mb-3"><label for="nama_lengkap" class="form-label fw-semibold">Nama Lengkap</label><input id="nama_lengkap" name="nama_lengkap" value="{{ old('nama_lengkap', $employee->nama_lengkap) }}" class="form-control" required></div>
                            <div class="mb-3"><label for="pangkat" class="form-label fw-semibold">Pangkat/Golongan</label><input id="pangkat" name="pangkat" value="{{ old('pangkat', $employee->pangkat_golongan) }}" class="form-control"></div>
                            <div class="row g-2"><div class="col-md-6"><label class="form-label">Username</label><input value="{{ $employee->username }}" class="form-control bg-light" readonly></div><div class="col-md-6"><label class="form-label">Role</label><input value="{{ ucfirst($employee->role) }}" class="form-control bg-light" readonly></div></div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-key"></i> Ganti Password</h6>
                    <p class="text-muted small">Kosongkan seluruh bagian password jika tidak ingin menggantinya.</p>
                    <div class="row g-3">
                        <div class="col-md-4"><label for="current_password" class="form-label">Password Saat Ini</label><input id="current_password" type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
                        <div class="col-md-4"><label for="password" class="form-label">Password Baru</label><input id="password" type="password" name="password" class="form-control" minlength="8" autocomplete="new-password"></div>
                        <div class="col-md-4"><label for="password_confirmation" class="form-label">Konfirmasi Password Baru</label><input id="password_confirmation" type="password" name="password_confirmation" class="form-control" minlength="8" autocomplete="new-password"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
                        <button class="btn btn-primary"><i class="bi bi-save"></i> Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
