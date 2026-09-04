@extends('layouts.app')

@section('title', 'Laporan Perjalanan Dinas - SIM-PD')
@section('brand', 'Laporan Perjalanan Dinas')
@section('page-subtitle', 'Lengkapi kegiatan dan hasil yang dicapai, simpulan dan saran, serta dokumentasi.')

@section('content')

    <div class="row justify-content-center">

        <div class="col-lg-9">

            <div class="workflow-steps mb-4 d-flex flex-sm-column" data-report-workflow aria-label="Tahap 2 dari 6">
                <span class="done" data-workflow-step="1">1. SPT</span>
                <span class="active" data-workflow-step="2">2. Laporan</span>
                <span data-workflow-step="3">3. Pratinjau Laporan</span>
                <span data-workflow-step="4">4. Realisasi</span>
                <span data-workflow-step="5">5. Verifikasi</span>
                <span data-workflow-step="6">6. Selesai</span>
            </div>

            <div data-report-form-section>
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
                        Silakan lengkapi
                        <strong>Kegiatan dan Hasil yang Dicapai</strong>
                        dan
                        <strong>Simpulan dan Saran</strong>.

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
                    <form id="travel-report-form-{{ $travel->id }}"
                        action="{{ route('travel-reports.update', [
                            'travel' => $travel->id,
                        ]) }}"
                        method="POST" enctype="multipart/form-data" data-report-preview-form>

                        @csrf
                        @method('PUT')
                        <h6 class="fw-bold border-bottom pb-2 mb-3">
                            C. Kegiatan dan Hasil yang Dicapai
                        </h6>


                        <div class="mb-4">

                            <label for="hasil_pelaksanaan" class="form-label fw-semibold">
                                Kegiatan dan Hasil yang Dicapai
                            </label>

                            <textarea id="hasil_pelaksanaan" name="hasil_pelaksanaan" rows="10" maxlength="20000"
                                class="form-control @error('hasil_pelaksanaan') is-invalid @enderror"
                                placeholder="Jelaskan kegiatan yang dilaksanakan dan hasil yang berhasil dicapai..." required>{{ old('hasil_pelaksanaan', $report?->hasil_pelaksanaan) }}</textarea>


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
                            D. Simpulan dan Saran
                        </h6>


                        <div class="mb-4">

                            <label for="kesimpulan" class="form-label fw-semibold">
                                Simpulan dan Saran
                            </label>

                            <textarea id="kesimpulan" name="kesimpulan" rows="6" maxlength="10000"
                                class="form-control @error('kesimpulan') is-invalid @enderror"
                                placeholder="Tuliskan simpulan dan saran dari perjalanan dinas..." required>{{ old('kesimpulan', $report?->kesimpulan) }}</textarea>


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
                                Maksimal 2 foto. Format JPG, JPEG, atau PNG; maksimal 5 MB per foto.
                            </div>

                            <div id="foto_dokumentasi_terpilih" class="small mt-2" aria-live="polite"></div>

                            @if ($errors->has('foto_dokumentasi') || $errors->has('foto_dokumentasi.*'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('foto_dokumentasi') ?: $errors->first('foto_dokumentasi.*') }}
                                </div>
                            @endif
                        </div>

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
                                Tanggal laporan di isi
                                saat laporan pertama kali disimpan.
                            </div>

                        </div>

                        <div class="form-action-bar">

                            <a href="{{ route('dashboard.user') }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i>
                                Kembali
                            </a>


                            <button type="button" class="btn btn-identity" data-report-preview-trigger
                                data-document-preview-trigger data-document-form="travel-report-form-{{ $travel->id }}"
                                data-document-url="{{ route('travel-reports.preview', ['travel' => $travel->id]) }}"
                                data-document-label="Laporan Perjalanan Dinas"
                                data-document-filename="Pratinjau_Laporan_{{ $travel->id }}.pdf"
                                aria-controls="report-document-preview-{{ $travel->id }}" aria-expanded="false">
                                <i class="bi bi-file-earmark-pdf"></i>
                                Pratinjau Laporan
                            </button>

                        </div>

                    </form>

                </div>

            </div>
            </div>

            <x-ui.document-preview
                id="report-document-preview-{{ $travel->id }}"
                :document-number="$travel->no_spt"
                :standalone="true"
                data-report-preview-section
            >
                <x-slot:footer>
                    <div class="report-document-preview-confirmation p-3">
                        <p class="mb-0 text-muted small">
                            Pastikan isi laporan sudah benar. Laporan hanya dapat disimpan satu kali.
                        </p>
                        <div class="report-document-preview-actions d-flex justify-content-around mt-3">
                            <button type="button" class="btn btn-outline-secondary" data-report-preview-review>
                                <i class="bi bi-pencil-square"></i> Periksa Kembali
                            </button>
                            <button type="button" class="btn btn-identity" data-report-preview-confirm disabled>
                                <i class="bi bi-check-circle"></i> Simpan &amp; Lanjut
                            </button>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.document-preview>

        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const input = document.getElementById('foto_dokumentasi');
            const selectedList = document.getElementById('foto_dokumentasi_terpilih');

            if (!input || !selectedList || typeof DataTransfer === 'undefined') {
                return;
            }

            const maxFiles = 2;
            const selectedFiles = [];
            const fileKey = (file) => `${file.name}:${file.size}:${file.lastModified}`;

            const renderSelectedFiles = function() {
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

                selectedFiles.forEach(function(file, index) {
                    const item = document.createElement('div');
                    item.className =
                        'd-flex align-items-center justify-content-between border rounded px-3 py-2';

                    const fileName = document.createElement('span');
                    fileName.className = 'text-break me-3';
                    fileName.textContent = `${index + 1}. ${file.name}`;

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn btn-sm btn-outline-danger flex-shrink-0';
                    removeButton.textContent = 'Hapus';
                    removeButton.addEventListener('click', function() {
                        selectedFiles.splice(index, 1);
                        syncInputFiles();
                    });

                    item.append(fileName, removeButton);
                    list.appendChild(item);
                });

                selectedList.appendChild(list);
            };

            const syncInputFiles = function() {
                const transfer = new DataTransfer();

                selectedFiles.forEach(function(file) {
                    transfer.items.add(file);
                });

                input.files = transfer.files;
                renderSelectedFiles();
            };

            input.addEventListener('change', function() {
                const incomingFiles = Array.from(input.files);
                const existingKeys = new Set(selectedFiles.map(fileKey));
                let exceedsLimit = false;

                incomingFiles.forEach(function(file) {
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
                    window.SimPdDialog.warning(
                        'Maksimal dua foto dokumentasi dapat dipilih.',
                        'Batas foto dokumentasi'
                    );
                }
            });

            renderSelectedFiles();
        });
    </script>

@endsection
