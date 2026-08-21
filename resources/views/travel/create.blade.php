@extends('layouts.app')
@section('title', 'Buat SPT Kolektif - SIM-PD')
@section('brand', 'Buat SPT Kolektif')

@section('content')
<div class="row justify-content-center"><div class="col-lg-9"><div class="card shadow-sm"><div class="card-body p-4">
<form action="{{ route('travel-orders.store') }}" method="post">@csrf
    <h6 class="text-muted border-bottom pb-2 mb-3">Data Surat & Tujuan</h6>
    <div class="mb-3"><label class="form-label fw-bold">Nomor SPT</label><input type="text" name="no_spt" value="{{ old('no_spt') }}" class="form-control" placeholder="2.23/157/LP.00.04/XI/2026" required></div>
    <div class="mb-3"><label class="form-label fw-bold">Menimbang</label><input type="text" name="menimbang" value="{{ old('menimbang') }}" class="form-control" required></div>
    <div class="row"><div class="col-md-6 mb-3"><label class="form-label fw-bold">Nomor Memo Internal</label><input type="text" name="no_memo" value="{{ old('no_memo') }}" class="form-control" required></div><div class="col-md-6 mb-3"><label class="form-label fw-bold">Tanggal Memo Internal</label><input type="date" name="tgl_memo" value="{{ old('tgl_memo') }}" class="form-control" required></div></div>
    <div class="mb-3"><label class="form-label fw-bold">Perihal Memo Internal</label><input type="text" name="perihal_memo" value="{{ old('perihal_memo') }}" class="form-control" required></div>
    <div class="mb-3"><label class="form-label fw-bold">Maksud Perjalanan Dinas</label><textarea name="maksud_perjalanan" class="form-control" rows="3" required>{{ old('maksud_perjalanan') }}</textarea></div>

    <h6 class="text-muted border-bottom pb-2 mb-3 mt-4">Daftar Pegawai yang Berangkat</h6>
    <div class="alert alert-info small py-2"><i class="bi bi-info-circle"></i> Tambahkan baris jika SPT ditujukan kepada lebih dari satu pegawai.</div>
    <div id="employee-container">
        @foreach(old('user_ids', ['']) as $selectedId)
        <div class="row mb-2 employee-row"><div class="col-10"><select name="user_ids[]" class="form-select" required><option value="">-- Pilih Pegawai --</option>@foreach($employees as $employee)<option value="{{ $employee->id }}" @selected((string)$selectedId === (string)$employee->id)>{{ $employee->nama_lengkap }}</option>@endforeach</select></div><div class="col-2"><button type="button" class="btn btn-danger w-100 remove-employee" {{ $loop->first ? 'disabled' : '' }}><i class="bi bi-trash"></i></button></div></div>
        @endforeach
    </div>
    <button type="button" id="add-employee" class="btn btn-sm btn-success mb-4"><i class="bi bi-plus-circle"></i> Tambah Pegawai Lain</button>

    <h6 class="text-muted border-bottom pb-2 mb-3">Detail Waktu & Anggaran</h6>
    <div class="row"><div class="col-md-6 mb-3"><label class="fw-bold">Kota Tujuan</label><select name="kota_tujuan" class="form-select" required><option value="">-- Pilih Kota --</option>@foreach($destinations as $destination)<option value="{{ $destination->kota_tujuan }}" @selected(old('kota_tujuan') === $destination->kota_tujuan)>{{ $destination->kota_tujuan }}</option>@endforeach</select></div><div class="col-md-6 mb-3"><label class="fw-bold">Tempat Berangkat</label><input type="text" name="tempat_berangkat" value="{{ old('tempat_berangkat', 'Pangkep') }}" class="form-control" required></div></div>
    <div class="row"><div class="col-md-4 mb-3"><label class="fw-bold">Tgl Berangkat</label><input type="date" name="tgl_berangkat" id="departure" value="{{ old('tgl_berangkat') }}" class="form-control" required></div><div class="col-md-4 mb-3"><label class="fw-bold">Tgl Kembali</label><input type="date" name="tgl_kembali" id="return" value="{{ old('tgl_kembali') }}" class="form-control" required></div><div class="col-md-4 mb-3"><label class="fw-bold">Lama (Hari)</label><input type="number" id="days" class="form-control bg-light" readonly></div></div>
    <div class="row"><div class="col-md-6 mb-3"><label class="fw-bold">Jenis Angkutan</label><select name="angkutan" class="form-select"><option @selected(old('angkutan') === 'Pesawat Udara')>Pesawat Udara</option><option @selected(old('angkutan') === 'Transportasi Darat')>Transportasi Darat</option></select></div><div class="col-md-6 mb-3"><label for="akun_anggaran" class="fw-bold">Akun Anggaran (MAK)</label><select id="akun_anggaran" name="akun_anggaran" class="form-select" required><option value="">-- Pilih MAK --</option>@foreach($budgetAccounts as $account)<option value="{{ $account->code }}" @selected(old('akun_anggaran', '4053.PDI.002.054.B.524111') === $account->code)>{{ $account->code }}{{ $account->description ? ' — '.$account->description : '' }}</option>@endforeach</select></div></div>
    <hr><div class="d-flex justify-content-between"><a href="{{ route('dashboard.officer') }}" class="btn btn-secondary">Kembali</a><button class="btn btn-identity">Simpan Semua SPT</button></div>
</form>
</div></div></div></div>
@endsection

@push('scripts')
<script>
const container = document.getElementById('employee-container');
document.getElementById('add-employee').addEventListener('click', () => {
    const row = container.querySelector('.employee-row').cloneNode(true);
    row.querySelector('select').value = '';
    row.querySelector('button').disabled = false;
    container.appendChild(row);
});
container.addEventListener('click', event => {
    const button = event.target.closest('.remove-employee');
    if (button && !button.disabled) button.closest('.employee-row').remove();
});
function calculateDays() {
    const start = document.getElementById('departure').value;
    const end = document.getElementById('return').value;
    if (!start || !end) return;
    const diff = (new Date(end + 'T00:00:00') - new Date(start + 'T00:00:00')) / 86400000;
    if (diff < 0) { alert('Tanggal kembali tidak boleh sebelum tanggal berangkat.'); document.getElementById('return').value = ''; document.getElementById('days').value = ''; return; }
    document.getElementById('days').value = diff + 1;
}
document.getElementById('departure').addEventListener('change', calculateDays);
document.getElementById('return').addEventListener('change', calculateDays);
calculateDays();
</script>
@endpush
