@extends('layouts.app')

@section('title', 'Laporan Perjalanan Dinas - SIM-PD')
@section('brand', 'Laporan Perjalanan Dinas')

@section('content')

    <div class="row justify-content-center">

        <div class="col-lg-9">

            <div class="card shadow-sm">
                <div class="card-header bg-identity text-white py-3">

                    <h5 class="mb-1">
                        <i class="bi bi-file-earmark-text"></i>
                        Laporan Hasil Perjalanan Dinas
                    </h5>

                    <small>
                        SPT:
                        {{ $travel->no_spt }}
                    </small>

                </div>


                <div class="card-body p-4">

                    <div class="alert alert-info">

                        <i class="bi bi-info-circle"></i>

                        Data pegawai dan perjalanan dinas diisi
                        otomatis oleh sistem.

                        Silakan lengkapi
                        <strong>Hasil Pelaksanaan Kegiatan</strong>
                        dan
                        <strong>Kesimpulan</strong>.

                    </div>


                    <h6 class="fw-bold border-bottom pb-2 mb-3">
                        A. Pegawai yang Ditugaskan
                    </h6>
                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Nama
                            </label>

                            <input type="text" class="form-control bg-light"
                                value="{{ $travel->pegawai?->nama_lengkap ?? '-' }}" readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                NIP
                            </label>

                            <input type="text" class="form-control bg-light" value="{{ $travel->pegawai?->nip ?? '-' }}"
                                readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Jabatan
                            </label>

                            <input type="text" class="form-control bg-light"
                                value="{{ $travel->pegawai?->jabatan ?? '-' }}" readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Pangkat / Golongan
                            </label>

                            <input type="text" class="form-control bg-light"
                                value="{{ $travel->pegawai?->pangkat_golongan ?? '-' }}" readonly>

                        </div>

                    </div>

                    <h6 class="fw-bold border-bottom pb-2 mb-3 mt-3">
                        B. Pelaksanaan Kegiatan
                    </h6>


                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Surat Perintah
                            </label>

                            <input type="text" class="form-control bg-light" value="{{ $travel->no_spt }}" readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Jadwal Kegiatan
                            </label>

                            <input type="text" class="form-control bg-light"
                                value="{{ $travel->tgl_berangkat?->format('d/m/Y') }} s.d. {{ $travel->tgl_kembali?->format('d/m/Y') }}"
                                readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Tempat Kegiatan
                            </label>

                            <input type="text" class="form-control bg-light" value="{{ $travel->kota_tujuan }}" readonly>

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label text-muted small">
                                Akun DIPA
                            </label>

                            <input type="text" class="form-control bg-light" value="{{ $travel->akun_anggaran }}"
                                readonly>

                        </div>


                        <div class="col-12 mb-4">

                            <label class="form-label text-muted small">
                                Maksud Kegiatan
                            </label>

                            <textarea class="form-control bg-light" rows="3" readonly>{{ $travel->maksud_perjalanan }}</textarea>

                        </div>

                    </div>
                    <form
                        action="{{ route('travel-reports.update', [
                            'travel' => $travel->id,
                        ]) }}"
                        method="POST" enctype="multipart/form-data">

                        @csrf
                        @method('PUT')
                        <h6 class="fw-bold border-bottom pb-2 mb-3">
                            C. Hasil Pelaksanaan Kegiatan
                        </h6>


                        <div class="mb-4">

                            <label for="hasil_pelaksanaan" class="form-label fw-semibold">
                                Hasil Pelaksanaan
                            </label>

                            <textarea id="hasil_pelaksanaan" name="hasil_pelaksanaan" rows="10" maxlength="20000"
                                class="form-control @error('hasil_pelaksanaan') is-invalid @enderror"
                                placeholder="Jelaskan hasil kegiatan yang telah dilaksanakan..." required>{{ old('hasil_pelaksanaan', $report?->hasil_pelaksanaan) }}</textarea>


                            <div class="form-text">
                                Jelaskan kegiatan yang dilakukan,
                                hasil yang diperoleh, pihak yang
                                ditemui, atau hal penting lainnya.
                            </div>


                            @error('hasil_pelaksanaan')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror

                        </div>

                        <h6 class="fw-bold border-bottom pb-2 mb-3">
                            D. Kesimpulan
                        </h6>


                        <div class="mb-4">

                            <label for="kesimpulan" class="form-label fw-semibold">
                                Kesimpulan
                            </label>

                            <textarea id="kesimpulan" name="kesimpulan" rows="6" maxlength="10000"
                                class="form-control @error('kesimpulan') is-invalid @enderror"
                                placeholder="Tuliskan kesimpulan dari perjalanan dinas..." required>{{ old('kesimpulan', $report?->kesimpulan) }}</textarea>


                            @error('kesimpulan')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror

                        </div>

                        <h6 class="fw-bold border-bottom pb-2 mb-3">
                            E. Foto Dokumentasi
                        </h6>

                        <div class="mb-4">
                            <label for="foto_dokumentasi" class="form-label fw-semibold">
                                Upload Foto Dokumentasi
                            </label>

                            <input id="foto_dokumentasi" type="file" name="foto_dokumentasi[]" multiple required
                                accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                class="form-control {{ $errors->has('foto_dokumentasi') || $errors->has('foto_dokumentasi.*') ? 'is-invalid' : '' }}">

                            <div class="form-text">
                                Wajib 1–2 foto. Format JPG, JPEG, atau PNG; maksimal 5 MB per foto.
                                Foto dapat dipilih sekaligus atau ditambahkan satu per satu.
                                Setiap foto akan dinormalisasi dan ditampilkan dalam bidang 10 × 10 cm pada laporan.
                            </div>

                            <div id="foto_dokumentasi_terpilih" class="small mt-2" aria-live="polite"></div>

                            @if ($errors->has('foto_dokumentasi') || $errors->has('foto_dokumentasi.*'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('foto_dokumentasi') ?: $errors->first('foto_dokumentasi.*') }}
                                </div>
                            @endif
                        </div>


                        {{-- ====================================== --}}
                        {{-- TANGGAL LAPORAN --}}
                        {{-- ====================================== --}}

                        <div class="alert alert-light border">

                            <div class="small text-muted">
                                Tanggal Laporan
                            </div>

                            <strong>
                                @if ($report)
                                    {{ $report->tanggal_laporan?->format('d/m/Y') }}
                                @else
                                    {{ now()->format('d/m/Y') }}
                                @endif
                            </strong>

                            <div class="small text-muted mt-1">
                                Tanggal laporan ditentukan otomatis
                                saat laporan pertama kali disimpan.
                            </div>

                        </div>


                        {{-- ====================================== --}}
                        {{-- BUTTON --}}
                        {{-- ====================================== --}}

                        <div class="d-flex justify-content-between mt-4">

                            <a href="{{ route('dashboard.user') }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i>
                                Kembali
                            </a>


                            <button type="submit" class="btn btn-identity">
                                <i class="bi bi-arrow-right-circle"></i>
                                Simpan & Lanjut ke Realisasi
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.getElementById('foto_dokumentasi');
            const selectedList = document.getElementById('foto_dokumentasi_terpilih');

            if (!input || !selectedList || typeof DataTransfer === 'undefined') {
                return;
            }

            const maxFiles = 2;
            const selectedFiles = [];
            const fileKey = (file) => `${file.name}:${file.size}:${file.lastModified}`;

            const renderSelectedFiles = function () {
                selectedList.replaceChildren();

                if (selectedFiles.length === 0) {
                    const emptyMessage = document.createElement('span');
                    emptyMessage.className = 'text-muted';
                    emptyMessage.textContent = 'Belum ada foto yang dipilih.';
                    selectedList.appendChild(emptyMessage);

                    return;
                }

                const list = document.createElement('div');
                list.className = 'd-grid gap-2';

                selectedFiles.forEach(function (file, index) {
                    const item = document.createElement('div');
                    item.className = 'd-flex align-items-center justify-content-between border rounded px-3 py-2';

                    const fileName = document.createElement('span');
                    fileName.className = 'text-break me-3';
                    fileName.textContent = `${index + 1}. ${file.name}`;

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
                    removeButton.textContent = 'Hapus';
                    removeButton.addEventListener('click', function () {
                        selectedFiles.splice(index, 1);
                        syncInputFiles();
                    });

                    item.append(fileName, removeButton);
                    list.appendChild(item);
                });

                selectedList.appendChild(list);
            };

            const syncInputFiles = function () {
                const transfer = new DataTransfer();

                selectedFiles.forEach(function (file) {
                    transfer.items.add(file);
                });

                input.files = transfer.files;
                renderSelectedFiles();
            };

            input.addEventListener('change', function () {
                const incomingFiles = Array.from(input.files);
                const existingKeys = new Set(selectedFiles.map(fileKey));
                let exceedsLimit = false;

                incomingFiles.forEach(function (file) {
                    const key = fileKey(file);

                    if (existingKeys.has(key)) {
                        return;
                    }

                    if (selectedFiles.length >= maxFiles) {
                        exceedsLimit = true;

                        return;
                    }

                    selectedFiles.push(file);
                    existingKeys.add(key);
                });

                syncInputFiles();

                if (exceedsLimit) {
                    alert('Maksimal dua foto dokumentasi dapat dipilih.');
                }
            });

            renderSelectedFiles();
        });
    </script>

@endsection
