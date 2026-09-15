@extends('layouts.app')
@section('title', 'Buat SPT - SIM-PD')
@section('brand', 'Buat Surat Perintah Tugas')
@section('content')
    @php
        $selectedEmployeeIds = collect(old('user_ids', []))
            ->filter(fn($id) => (string) $id !== '')
            ->map(fn($id) => (string) $id)
            ->unique()
            ->values();
        $employeesById = $employees->keyBy(fn($employee) => (string) $employee->id);
        $employeeOptions = $selectedEmployeeIds
            ->map(fn($id) => $employeesById->get($id))
            ->filter()
            ->concat($employees->reject(fn($employee) => $selectedEmployeeIds->contains((string) $employee->id)));
        $numberMode = old('spt_number_mode', \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL);
    @endphp

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form action="{{ route('travel-orders.store') }}" method="post">
                        @csrf
                        <h6 class="text-muted border-bottom pb-2 mb-3">1. Template dan Cara Penerbitan</h6>
                        <div class="mb-3">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                                <label class="form-label fw-bold mb-0">Template SPT</label>
                                <a href="{{ route('travel-orders.create') }}" class="small"><i
                                        class="bi bi-arrow-left-circle me-1"></i>Ganti template</a>
                            </div>
                            <input type="text" class="form-control bg-light" value="{{ $selectedTemplate['label'] }}"
                                readonly>
                            @if ($selectedTemplate['id'])
                                <input type="hidden" name="spt_template_id" value="{{ $selectedTemplate['id'] }}">
                            @endif
                            @if ($selectedTemplate['variant'])
                                <input type="hidden" name="spt_template_variant"
                                    value="{{ $selectedTemplate['variant'] }}">
                            @endif
                        </div>

                        <fieldset class="mb-3" data-spt-number-mode>
                            <legend class="form-label fw-bold mb-2">Cara Penerbitan</legend>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input id="numberModeExternal" class="form-check-input" type="radio"
                                        name="spt_number_mode"
                                        value="{{ \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL }}"
                                        @checked($numberMode === \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL)>
                                    <label class="form-check-label" for="numberModeExternal">Proses lewat SRIKANDI</label>
                                </div>
                                <div class="form-check">
                                    <input id="numberModeManual" class="form-check-input" type="radio"
                                        name="spt_number_mode" value="{{ \App\Models\PerjalananDinas::NUMBER_MODE_MANUAL }}"
                                        @checked($numberMode === \App\Models\PerjalananDinas::NUMBER_MODE_MANUAL)>
                                    <label class="form-check-label" for="numberModeManual">Isi nomor sendiri</label>
                                </div>
                            </div>
                            <div class="mt-2" data-spt-number-parameter @if ($numberMode !== \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL) hidden @endif>
                                <input type="text" class="form-control bg-light"
                                    value="{{ \App\Models\PerjalananDinas::NUMBER_PLACEHOLDER }}" readonly>
                                <div class="form-text">SPT disimpan sebagai konsep. Nomor naskah dan tanda tangan akan diproses di SRIKANDI.</div>
                            </div>
                            <div class="mt-2" data-spt-number-manual @if ($numberMode !== \App\Models\PerjalananDinas::NUMBER_MODE_MANUAL) hidden @endif>
                                <label for="no_spt" class="visually-hidden">Nomor SPT manual</label>
                                <input id="no_spt" type="text" name="no_spt" value="{{ old('no_spt') }}"
                                    class="form-control" placeholder="2.23/157/LP.00.04/XI/2026" maxlength="50"
                                    @if ($numberMode === \App\Models\PerjalananDinas::NUMBER_MODE_MANUAL) required @endif>
                            </div>
                            @error('spt_number_mode')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            @error('no_spt')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </fieldset>
                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-4">2. Dasar Surat</h6>
                        <div class="mb-3"><label class="form-label fw-bold">Menimbang</label><input type="text"
                                name="menimbang" value="{{ old('menimbang') }}" class="form-control" required></div>
                        @if ($selectedTemplate['uses_memo'])
                            <div class="row">
                                <div class="col-md-6 mb-3"><label class="form-label fw-bold">Nomor Memo
                                        Internal</label><input type="text" name="no_memo" value="{{ old('no_memo') }}"
                                        class="form-control" required></div>
                                <div class="col-md-6 mb-3"><label class="form-label fw-bold">Tanggal Memo
                                        Internal</label><input type="date" name="tgl_memo" value="{{ old('tgl_memo') }}"
                                        class="form-control" required></div>
                            </div>
                            <div class="mb-3"><label class="form-label fw-bold">Perihal Memo Internal</label><input
                                    type="text" name="perihal_memo" value="{{ old('perihal_memo') }}"
                                    class="form-control" required></div>
                        @endif
                        <div class="mb-3"><label class="form-label fw-bold">Maksud Perjalanan Dinas</label>
                            <textarea name="maksud_perjalanan" class="form-control" rows="3" required>{{ old('maksud_perjalanan') }}</textarea>
                        </div>

                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-4">3. Pegawai yang Ditugaskan</h6>
                        <div class="mb-4 spt-searchable-field spt-employee-dropdown-field">
                            <label id="employeeSelectLabel" for="employeeSelect" class="form-label fw-bold">Pegawai</label>
                            <select id="employeeSelect" name="user_ids[]" class="form-select" multiple required
                                data-spt-searchable="employees" data-spt-layout="dropdown-search"
                                data-placeholder="Cari dan pilih pegawai">
                                @foreach ($employeeOptions as $employee)
                                    @php
                                        $employeeDescription = collect([
                                            $employee->nip ? 'NIP. ' . $employee->nip : null,
                                            $employee->jabatan,
                                        ])
                                            ->filter()
                                            ->implode(' · ');
                                    @endphp
                                    <option value="{{ $employee->id }}" @selected($selectedEmployeeIds->contains((string) $employee->id))
                                        data-label-description="{{ $employeeDescription }}"
                                        data-custom-properties="{{ json_encode(
                                            [
                                                'nip' => (string) ($employee->nip ?? ''),
                                                'position' => (string) ($employee->jabatan ?? ''),
                                            ],
                                            JSON_UNESCAPED_UNICODE,
                                        ) }}">
                                        {{ $employee->nama_lengkap }}
                                    </option>
                                @endforeach
                            </select>
                            @error('user_ids')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <h6 class="text-muted border-bottom pb-2 mb-3">4. Tujuan, Jadwal, dan Anggaran</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3 spt-searchable-field"><label id="destinationSelectLabel"
                                    for="destinationSelect" class="fw-bold mb-2">Kota Tujuan</label>
                                <select id="destinationSelect" name="kota_tujuan" class="form-select" required
                                    data-spt-searchable="destination" data-placeholder="Cari kota tujuan">
                                    <option value="" placeholder>-- Pilih Kota --</option>
                                    @foreach ($destinations as $destination)
                                        @php($provinceName = $destination->province?->name ?? '')
                                        <option value="{{ $destination->kota_tujuan }}" @selected(old('kota_tujuan') === $destination->kota_tujuan)
                                            data-label-description="{{ $provinceName }}"
                                            data-custom-properties="{{ json_encode(
                                                [
                                                    'province' => $provinceName,
                                                ],
                                                JSON_UNESCAPED_UNICODE,
                                            ) }}">
                                            {{ $destination->kota_tujuan }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">Tempat Berangkat</label>
                                <input type="text" name="tempat_berangkat"
                                    value="{{ old('tempat_berangkat', 'Pangkep') }}" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="fw-bold">Tgl Berangkat</label><input type="date"
                                    name="tgl_berangkat" id="departure" value="{{ old('tgl_berangkat') }}"
                                    class="form-control" required></div>
                            <div class="col-md-4 mb-3"><label class="fw-bold">Tgl Kembali</label><input type="date"
                                    name="tgl_kembali" id="return" value="{{ old('tgl_kembali') }}"
                                    class="form-control" required></div>
                            <div class="col-md-4 mb-3"><label class="fw-bold">Lama (Hari)</label><input type="number"
                                    id="days" class="form-control bg-light" readonly></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="fw-bold">Jenis Angkutan</label><select
                                    id="transportSelect" name="angkutan" class="form-select">
                                    <option @selected(old('angkutan') === 'Pesawat Udara')>Pesawat Udara</option>
                                    <option @selected(old('angkutan') === 'Transportasi Darat')>Transportasi Darat</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="daily_allowance_category" class="fw-bold">Kategori Uang Harian</label>
                                <select id="daily_allowance_category" name="daily_allowance_category"
                                    class="form-select">
                                    @foreach ($dailyAllowanceCategories as $value => $label)
                                        <option value="{{ $value }}" @selected(old('daily_allowance_category', 'outside_city') === $value)>
                                            {{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('daily_allowance_category')
                                    <div class="text-danger small">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4 mb-3"><label for="akun_anggaran" class="fw-bold">Akun Anggaran
                                    (MAK)</label><select id="akun_anggaran" name="akun_anggaran" class="form-select"
                                    required>
                                    <option value="">-- Pilih MAK --</option>
                                    @foreach ($budgetAccounts as $account)
                                        <option value="{{ $account->code }}" @selected(old('akun_anggaran', '4053.PDI.002.054.B.524111') === $account->code)>
                                            {{ $account->code }}{{ $account->description ? ' — ' . $account->description : '' }}
                                        </option>
                                    @endforeach
                                </select></div>
                        </div>
                        <x-ui.spt-cost-preview />
                        <div class="form-action-bar"><a href="{{ route('dashboard.officer') }}"
                                class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a><button
                                class="btn btn-identity"><i class="bi bi-check-circle-fill"></i> Simpan SPT</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function calculateDays() {
            const start = document.getElementById('departure').value;
            const end = document.getElementById('return').value;
            if (!start || !end) return;
            const diff = (new Date(end + 'T00:00:00') - new Date(start + 'T00:00:00')) / 86400000;
            if (diff < 0) {
                window.SimPdDialog.warning(
                    'Tanggal kembali tidak boleh sebelum tanggal berangkat.',
                    'Tanggal perjalanan tidak valid'
                );
                document.getElementById('return').value = '';
                document.getElementById('days').value = '';
                return;
            }
            document.getElementById('days').value = diff + 1;
        }
        document.getElementById('departure').addEventListener('change', calculateDays);
        document.getElementById('return').addEventListener('change', calculateDays);
        calculateDays();
    </script>
@endpush
