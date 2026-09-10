@extends('layouts.app')

@section('title', 'Edit SPT - SIM-PD')
@section('brand', 'Edit Surat Tugas')
@section('page-subtitle', 'Perbarui data kolektif selama seluruh anggota masih berstatus Siap Berjalan.')

@section('content')

    @php
        $selectedEmployeeIds = collect(old(
            'user_ids',
            $travels->pluck('user_id')->map(fn ($id) => (string) $id)->values()->all(),
        ))
            ->filter(fn ($id) => (string) $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
        $employeesById = $employees->keyBy(fn ($employee) => (string) $employee->id);
        $employeeOptions = $selectedEmployeeIds
            ->map(fn ($id) => $employeesById->get($id))
            ->filter()
            ->concat($employees->reject(fn ($employee) => $selectedEmployeeIds->contains((string) $employee->id)));
    @endphp

    <div class="row justify-content-center">

        <div class="col-lg-9">

            <div class="card shadow-sm">

                <div class="card-header bg-white py-3">

                    <div class="d-flex justify-content-between align-items-center">

                        <div>
                            <h5 class="mb-1">
                                Edit Surat Tugas
                            </h5>

                            <div class="text-muted small">
                                {{ $travel->sptOperationalReference() }}
                            </div>
                        </div>

                        <a href="{{ route('travel-orders.show', [
                            'sptGroupId' => $travel->spt_group_id,
                        ]) }}"
                            class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i>
                            Kembali
                        </a>

                    </div>

                </div>


                <div class="card-body p-4">

                    <div class="alert alert-warning small">

                        <i class="bi bi-info-circle"></i>

                        Perubahan pada data Surat Tugas akan
                        diterapkan kepada seluruh pegawai
                        dalam SPT ini.

                        SPT hanya dapat diubah selama seluruh
                        pegawai masih berstatus
                        <strong>Siap Berjalan</strong>.

                    </div>


                    <form
                        action="{{ route('travel-orders.update', [
                            'sptGroupId' => $travel->spt_group_id,
                        ]) }}"
                        method="POST">

                        @csrf
                        @method('PUT')


                        {{-- ========================================== --}}
                        {{-- DATA SURAT --}}
                        {{-- ========================================== --}}

                        <h6 class="text-muted border-bottom pb-2 mb-3">
                            Data Surat & Tujuan
                        </h6>


                        <div class="mb-3">
                            <label class="form-label fw-bold">Nomor SPT</label>
                            @if ($travel->spt_number_mode === \App\Models\PerjalananDinas::NUMBER_MODE_MANUAL)
                                <input type="text" name="no_spt" value="{{ old('no_spt', $travel->no_spt) }}"
                                    class="form-control" maxlength="50" required>
                            @else
                                <input type="text" value="{{ $travel->sptDocumentNumber() }}" class="form-control bg-light" readonly>
                                <div class="form-text">Parameter Srikandi dikunci dan tetap digunakan pada Surat Tugas.</div>
                            @endif
                        </div>


                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Menimbang
                            </label>

                            <input type="text" name="menimbang"
                                value="{{ old('menimbang', $travel->menimbang) }}"
                                class="form-control" required>

                        </div>


                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Template SPT
                            </label>

                            <input type="text" class="form-control bg-light" readonly
                                value="{{ $templateLabel }}">
                            <div class="form-text">
                                Template dikunci saat SPT dibuat dan tidak dapat diubah.
                            </div>

                        </div>


                        <div class="row">

                            @if ($usesMemo)
                                <div class="col-md-4 mb-3">

                                    <label class="form-label fw-bold">
                                        Nomor Memo Internal
                                    </label>

                                    <input type="text" name="no_memo"
                                        value="{{ old('no_memo', $travel->no_memo) }}"
                                        class="form-control" required>

                                </div>
                            @endif


                            <div class="col-md-4 mb-3">

                                <label for="daily_allowance_category" class="fw-bold">
                                    Kategori Uang Harian
                                </label>

                                <select id="daily_allowance_category" name="daily_allowance_category" class="form-select">
                                    @foreach($dailyAllowanceCategories as $value => $label)
                                        <option value="{{ $value }}" @selected(old('daily_allowance_category', $travel->daily_allowance_category ?? 'outside_city') === $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">SPT lama tetap menggunakan sumber tarif sebelumnya.</div>
                                @error('daily_allowance_category')
                                    <div class="text-danger small">{{ $message }}</div>
                                @enderror

                            </div>


                            @if ($usesMemo)
                                <div class="col-md-4 mb-3">

                                    <label class="form-label fw-bold">
                                        Tanggal Memo Internal
                                    </label>

                                    <input type="date" name="tgl_memo"
                                        value="{{ old('tgl_memo', $travel->tgl_memo?->format('Y-m-d')) }}"
                                        class="form-control" required>

                                </div>
                            @endif

                        </div>


                        @if ($usesMemo)
                            <div class="mb-3">

                                <label class="form-label fw-bold">
                                    Perihal Memo Internal
                                </label>

                                <input type="text" name="perihal_memo"
                                    value="{{ old('perihal_memo', $travel->perihal_memo) }}"
                                    class="form-control" required>

                            </div>
                        @endif


                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Maksud Perjalanan Dinas
                            </label>

                            <textarea name="maksud_perjalanan" class="form-control" rows="3" required>{{ old('maksud_perjalanan', $travel->maksud_perjalanan) }}</textarea>

                        </div>


                        {{-- ========================================== --}}
                        {{-- PEGAWAI --}}
                        {{-- ========================================== --}}

                        <h6 class="text-muted border-bottom pb-2 mb-3 mt-4">
                            Daftar Pegawai yang Berangkat
                        </h6>


                        <div class="alert alert-info small py-2">

                            <i class="bi bi-people"></i>

                            Pegawai dapat ditambahkan atau
                            dikeluarkan dari Surat Tugas selama
                            proses perjalanan belum berjalan.

                        </div>


                        <div class="mb-4 spt-searchable-field">
                            <label id="employeeSelectLabel" for="employeeSelect" class="form-label fw-bold">
                                Pegawai
                            </label>

                            <select id="employeeSelect" name="user_ids[]" class="form-select" multiple required
                                data-spt-searchable="employees" data-placeholder="Cari dan pilih pegawai">
                                @foreach ($employeeOptions as $employee)
                                    @php
                                        $employeeDescription = collect([
                                            $employee->nip ? 'NIP. '.$employee->nip : null,
                                            $employee->jabatan,
                                        ])->filter()->implode(' · ');
                                    @endphp
                                    <option value="{{ $employee->id }}" @selected($selectedEmployeeIds->contains((string) $employee->id))
                                        data-label-description="{{ $employeeDescription }}"
                                        data-custom-properties="{{ json_encode([
                                            'nip' => (string) ($employee->nip ?? ''),
                                            'position' => (string) ($employee->jabatan ?? ''),
                                        ], JSON_UNESCAPED_UNICODE) }}">
                                        {{ $employee->nama_lengkap }}
                                    </option>
                                @endforeach
                            </select>

                            @error('user_ids')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>


                        {{-- ========================================== --}}
                        {{-- PERJALANAN --}}
                        {{-- ========================================== --}}

                        <h6 class="text-muted border-bottom pb-2 mb-3">
                            Detail Waktu & Anggaran
                        </h6>


                        <div class="row">

                            <div class="col-md-6 mb-3 spt-searchable-field">

                                <label id="destinationSelectLabel" for="destinationSelect" class="fw-bold mb-2">
                                    Kota Tujuan
                                </label>

                                <select id="destinationSelect" name="kota_tujuan" class="form-select" required
                                    data-spt-searchable="destination" data-placeholder="Cari kota tujuan">

                                    <option value="" placeholder>
                                        -- Pilih Kota --
                                    </option>

                                    @foreach ($destinations as $destination)
                                        @php($provinceName = $destination->province?->name ?? '')
                                        <option value="{{ $destination->kota_tujuan }}" @selected(old('kota_tujuan', $travel->kota_tujuan) === $destination->kota_tujuan)
                                            data-label-description="{{ $provinceName }}"
                                            data-custom-properties="{{ json_encode([
                                                'province' => $provinceName,
                                            ], JSON_UNESCAPED_UNICODE) }}">
                                            {{ $destination->kota_tujuan }}
                                        </option>
                                    @endforeach

                                </select>
                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="fw-bold">
                                    Tempat Berangkat
                                </label>

                                <input type="text" name="tempat_berangkat"
                                    value="{{ old('tempat_berangkat', $travel->tempat_berangkat) }}"
                                    class="form-control" required>

                            </div>

                        </div>


                        <div class="row">

                            <div class="col-md-4 mb-3">

                                <label class="fw-bold">
                                    Tgl Berangkat
                                </label>

                                <input type="date" name="tgl_berangkat" id="departure"
                                    value="{{ old('tgl_berangkat', $travel->tgl_berangkat?->format('Y-m-d')) }}"
                                    class="form-control" required>

                            </div>


                            <div class="col-md-4 mb-3">

                                <label class="fw-bold">
                                    Tgl Kembali
                                </label>

                                <input type="date" name="tgl_kembali" id="return"
                                    value="{{ old('tgl_kembali', $travel->tgl_kembali?->format('Y-m-d')) }}"
                                    class="form-control" required>

                            </div>


                            <div class="col-md-4 mb-3">

                                <label class="fw-bold">
                                    Lama (Hari)
                                </label>

                                <input type="number" id="days" class="form-control bg-light" readonly>

                            </div>

                        </div>


                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="fw-bold">
                                    Jenis Angkutan
                                </label>

                                <select id="transportSelect" name="angkutan" class="form-select" required>

                                    <option value="Pesawat Udara" @selected(old('angkutan', $travel->angkutan) === 'Pesawat Udara')>
                                        Pesawat Udara
                                    </option>

                                    <option value="Transportasi Darat" @selected(old('angkutan', $travel->angkutan) === 'Transportasi Darat')>
                                        Transportasi Darat
                                    </option>

                                </select>
                                <div class="form-text">Officer tetap mengonfirmasi moda yang digunakan.</div>

                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="fw-bold">
                                    Akun Anggaran (MAK)
                                </label>

                                <select name="akun_anggaran" class="form-select" required>
                                    @foreach($budgetAccounts as $account)
                                        <option value="{{ $account->code }}" @selected(old('akun_anggaran', $travel->akun_anggaran) === $account->code)>
                                            {{ $account->code }}{{ $account->description ? ' — '.$account->description : '' }}
                                        </option>
                                    @endforeach
                                </select>

                            </div>

                        </div>


                        <x-ui.spt-cost-preview :group-id="$travel->spt_group_id" />

                        <hr>


                        <div class="form-action-bar">

                            <a href="{{ route('travel-orders.show', [
                                'sptGroupId' => $travel->spt_group_id,
                            ]) }}"
                                class="btn btn-secondary">
                                Batal
                            </a>


                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-check-circle"></i>
                                Simpan Perubahan
                            </button>

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

            const start =
                document.getElementById(
                    'departure'
                ).value;

            const end =
                document.getElementById(
                    'return'
                ).value;

            const daysInput =
                document.getElementById(
                    'days'
                );

            if (!start || !end) {

                daysInput.value = '';

                return;
            }

            const startDate =
                new Date(
                    start + 'T00:00:00'
                );

            const endDate =
                new Date(
                    end + 'T00:00:00'
                );

            const diff =
                (
                    endDate - startDate
                ) /
                86400000;

            if (diff < 0) {

                window.SimPdDialog.warning(
                    'Tanggal kembali tidak boleh sebelum tanggal berangkat.',
                    'Tanggal perjalanan tidak valid'
                );

                document.getElementById(
                    'return'
                ).value = '';

                daysInput.value = '';

                return;
            }

            daysInput.value =
                diff + 1;
        }


        document
            .getElementById('departure')
            .addEventListener(
                'change',
                calculateDays
            );

        document
            .getElementById('return')
            .addEventListener(
                'change',
                calculateDays
            );

        calculateDays();

    </script>
@endpush
