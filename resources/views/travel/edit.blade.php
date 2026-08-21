@extends('layouts.app')

@section('title', 'Edit SPT - SIM-PD')
@section('brand', 'Edit Surat Tugas')

@section('content')

    @php
        $selectedEmployeeIds = old(
            'user_ids',
            $travels->pluck('user_id')->map(fn($id) => (string) $id)->values()->all(),
        );
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
                                {{ $travel->no_spt }}
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

                            <label class="form-label fw-bold">
                                Nomor SPT
                            </label>

                            <input type="text" name="no_spt"
                                value="{{ old('no_spt', $travel->no_spt) }}"
                                class="form-control" required>

                        </div>


                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Menimbang
                            </label>

                            <input type="text" name="menimbang"
                                value="{{ old('menimbang', $travel->menimbang) }}"
                                class="form-control" required>

                        </div>


                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-bold">
                                    Nomor Memo Internal
                                </label>

                                <input type="text" name="no_memo"
                                    value="{{ old('no_memo', $travel->no_memo) }}"
                                    class="form-control" required>

                            </div>


                            <div class="col-md-6 mb-3">

                                <label class="form-label fw-bold">
                                    Tanggal Memo Internal
                                </label>

                                <input type="date" name="tgl_memo"
                                    value="{{ old('tgl_memo', $travel->tgl_memo?->format('Y-m-d')) }}"
                                    class="form-control" required>

                            </div>

                        </div>


                        <div class="mb-3">

                            <label class="form-label fw-bold">
                                Perihal Memo Internal
                            </label>

                            <input type="text" name="perihal_memo"
                                value="{{ old('perihal_memo', $travel->perihal_memo) }}"
                                class="form-control" required>

                        </div>


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


                        <div id="employee-container">

                            @foreach ($selectedEmployeeIds as $selectedId)
                                <div class="row mb-2 employee-row">

                                    <div class="col-10">

                                        <select name="user_ids[]" class="form-select" required>

                                            <option value="">
                                                -- Pilih Pegawai --
                                            </option>

                                            @foreach ($employees as $employee)
                                                <option value="{{ $employee->id }}" @selected((string) $selectedId === (string) $employee->id)>
                                                    {{ $employee->nama_lengkap }}

                                                    @if ($employee->nip)
                                                        -
                                                        {{ $employee->nip }}
                                                    @endif
                                                </option>
                                            @endforeach

                                        </select>

                                    </div>


                                    <div class="col-2">

                                        <button type="button" class="btn btn-danger w-100 remove-employee">
                                            <i class="bi bi-trash"></i>
                                        </button>

                                    </div>

                                </div>
                            @endforeach

                        </div>


                        <button type="button" id="add-employee" class="btn btn-sm btn-success mb-4">
                            <i class="bi bi-plus-circle"></i>
                            Tambah Pegawai Lain
                        </button>


                        {{-- ========================================== --}}
                        {{-- PERJALANAN --}}
                        {{-- ========================================== --}}

                        <h6 class="text-muted border-bottom pb-2 mb-3">
                            Detail Waktu & Anggaran
                        </h6>


                        <div class="row">

                            <div class="col-md-6 mb-3">

                                <label class="fw-bold">
                                    Kota Tujuan
                                </label>

                                <select name="kota_tujuan" class="form-select" required>

                                    <option value="">
                                        -- Pilih Kota --
                                    </option>

                                    @foreach ($destinations as $destination)
                                        <option value="{{ $destination->kota_tujuan }}" @selected(old('kota_tujuan', $travel->kota_tujuan) === $destination->kota_tujuan)>
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

                                <select name="angkutan" class="form-select" required>

                                    <option value="Pesawat Udara" @selected(old('angkutan', $travel->angkutan) === 'Pesawat Udara')>
                                        Pesawat Udara
                                    </option>

                                    <option value="Transportasi Darat" @selected(old('angkutan', $travel->angkutan) === 'Transportasi Darat')>
                                        Transportasi Darat
                                    </option>

                                </select>

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


                        <hr>


                        <div class="d-flex justify-content-between">

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
        const container =
            document.getElementById(
                'employee-container'
            );

        const addEmployeeButton =
            document.getElementById(
                'add-employee'
            );


        function refreshRemoveButtons() {

            const rows =
                container.querySelectorAll(
                    '.employee-row'
                );

            rows.forEach(row => {

                const button =
                    row.querySelector(
                        '.remove-employee'
                    );

                /*
                 * Minimal harus ada satu pegawai.
                 */
                button.disabled =
                    rows.length === 1;
            });
        }


        addEmployeeButton.addEventListener(
            'click',
            () => {

                const sourceRow =
                    container.querySelector(
                        '.employee-row'
                    );

                const newRow =
                    sourceRow.cloneNode(true);

                newRow.querySelector(
                    'select'
                ).value = '';

                container.appendChild(
                    newRow
                );

                refreshRemoveButtons();
            }
        );


        container.addEventListener(
            'click',
            event => {

                const button =
                    event.target.closest(
                        '.remove-employee'
                    );

                if (!button) {
                    return;
                }

                const rows =
                    container.querySelectorAll(
                        '.employee-row'
                    );

                if (rows.length <= 1) {
                    return;
                }

                button
                    .closest('.employee-row')
                    .remove();

                refreshRemoveButtons();
            }
        );


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

                alert(
                    'Tanggal kembali tidak boleh sebelum tanggal berangkat.'
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


        refreshRemoveButtons();
        calculateDays();
    </script>
@endpush
