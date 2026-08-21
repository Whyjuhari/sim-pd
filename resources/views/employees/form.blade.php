@extends('layouts.app')
@section('title', $employee->exists ? 'Edit Pegawai - SIM-PD' : 'Tambah Pegawai - SIM-PD')
@section('brand', $employee->exists ? 'Edit Pengguna' : 'Tambah Pengguna')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-identity mb-4">
                        {{ $employee->exists ? 'Edit Data Pengguna' : 'Tambah User / Pegawai Baru' }}</h5>
                    <form action="{{ $employee->exists ? route('employees.update') : route('employees.store') }}"
                        method="post" enctype="multipart/form-data">
                        @csrf
                        @if ($employee->exists)
                            <input type="hidden" name="id" value="{{ $employee->id }}">
                        @endif
                        @if ($employee->exists)
                            <div class="text-center mb-3"><img src="{{ $employee->photoUrl() }}" width="100"
                                    height="100" class="rounded-circle border" style="object-fit:cover"></div>
                        @endif
                        <div class="mb-3"><label class="fw-bold">Foto Profil</label><input type="file" name="foto"
                                class="form-control" accept=".jpg,.jpeg,.png"><small class="text-muted">JPG/PNG, maksimal 2
                                MB.</small></div>
                        <div class="mb-4">

                            <label class="fw-bold">
                                Tanda Tangan Pegawai
                            </label>

                            @if ($employee->exists && $employee->hasSignature())
                                <div class="border rounded p-3 mb-3 bg-light">

                                    <div class="d-flex flex-wrap align-items-center gap-3">

                                        <div class="bg-white border rounded p-2">
                                            <img src="{{ route('employees.signature', [
                                                'employee' => $employee->id,
                                            ]) }}"
                                                alt="Tanda tangan {{ $employee->nama_lengkap }}"
                                                style="
                            width: 180px;
                            height: 90px;
                            object-fit: contain;
                        ">
                                        </div>


                                        <div>

                                            <div class="text-success fw-semibold mb-1">
                                                <i class="bi bi-check-circle-fill"></i>
                                                Tanda tangan tersedia
                                            </div>

                                            <small class="text-muted d-block mb-2">
                                                Upload PNG baru jika ingin
                                                mengganti tanda tangan saat ini.
                                            </small>


                                            <button type="submit" form="delete-signature-form"
                                                class="btn btn-sm btn-outline-danger"
                                                onclick="
                            return confirm(
                                'Yakin ingin menghapus tanda tangan pegawai ini?'
                            );
                        ">
                                                <i class="bi bi-trash"></i>
                                                Hapus Tanda Tangan
                                            </button>

                                        </div>

                                    </div>

                                </div>
                            @elseif($employee->exists)
                                <div class="alert alert-warning py-2">

                                    <i class="bi bi-exclamation-triangle"></i>

                                    Pegawai ini belum memiliki
                                    tanda tangan digital.

                                </div>
                            @endif
                            <input type="file" name="ttd" class="form-control @error('ttd') is-invalid @enderror"
                                accept="image/png,.png">


                            <small class="text-muted">
                                Format wajib PNG, maksimal 1 MB.
                                Disarankan menggunakan background transparan
                                dan gambar sudah di-crop rapat.
                            </small>


                            @error('ttd')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror

                        </div>
                        <div class="mb-3"><label class="fw-bold">Nama Lengkap</label><input type="text"
                                name="nama_lengkap" class="form-control"
                                value="{{ old('nama_lengkap', $employee->nama_lengkap) }}" required></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label for="nip" class="fw-bold">NIP</label><input id="nip" type="text"
                                    name="nip" class="form-control" value="{{ old('nip', $employee->nip) }}">
                                <small class="text-muted">Wajib dan unik khusus role Pegawai.</small>
                            </div>
                            <div class="col-md-6 mb-3"><label class="fw-bold">Pangkat/Golongan</label><input type="text"
                                    name="pangkat" class="form-control"
                                    value="{{ old('pangkat', $employee->pangkat_golongan) }}"></div>
                        </div>
                        <div class="mb-3"><label class="fw-bold">Jabatan</label><input type="text" name="jabatan"
                                class="form-control" value="{{ old('jabatan', $employee->jabatan) }}"></div>
                        <hr>
                        <div class="mb-3"><label class="fw-bold">Username</label><input type="text" name="username"
                                class="form-control" value="{{ old('username', $employee->username) }}" required></div>
                        <div class="mb-3"><label
                                class="fw-bold">{{ $employee->exists ? 'Password Baru (kosongkan jika tidak diganti)' : 'Password Default' }}</label><input
                                type="{{ $employee->exists ? 'password' : 'text' }}" name="password" class="form-control"
                                value="{{ $employee->exists ? '' : '123456' }}" {{ $employee->exists ? '' : 'readonly' }}>
                        </div>
                        <div class="mb-4"><label for="role" class="fw-bold text-danger">Role / Hak Akses</label><select
                                id="role" name="role" class="form-select" required>
                                @foreach ($roles as $role)
                                    <option value="{{ $role }}" @selected(old('role', $employee->role ?: 'user') === $role)>{{ ucfirst($role) }}
                                    </option>
                                @endforeach
                            </select></div>
                        <div class="d-flex justify-content-between"><a href="{{ url()->previous() }}"
                                class="btn btn-secondary">Batal</a><button class="btn btn-identity">Simpan Data</button>
                        </div>
                    </form>
                    @if ($employee->exists && $employee->hasSignature())
                        <form id="delete-signature-form"
                            action="{{ route('employees.signature.destroy', [
                                'employee' => $employee->id,
                            ]) }}"
                            method="POST" class="d-none">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const role = document.getElementById('role');
    const nip = document.getElementById('nip');
    const syncNipRequirement = () => nip.required = role.value === 'user';
    role.addEventListener('change', syncNipRequirement);
    syncNipRequirement();
});
</script>
@endpush
