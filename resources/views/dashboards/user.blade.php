@extends('layouts.app')
@section('title', 'Dashboard Pegawai - SIM-PD')
@section('brand', 'Tugas Perjalanan Saya')
@section('page-subtitle',
    'Pantau tahapan perjalanan, lengkapi laporan dan realisasi, lalu unduh dokumen yang
    tersedia.')

@section('content')
    @if (session('warning'))
        <div class="alert alert-warning alert-dismissible fade show app-alert" role="alert"><i
                class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('warning') }}<button type="button"
                class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>
    @endif

    <section class="employee-identity-card mb-4">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3">
            <img src="{{ auth()->user()->photoUrl() }}" alt="Foto {{ auth()->user()->nama_lengkap }}"
                class="employee-identity-avatar">
            <div class="grow min-w-0">
                <h2 class="h5 mb-2 text-break">{{ auth()->user()->nama_lengkap }}</h2>
                <div class="employee-identity-meta">
                    <span><i class="bi bi-person-vcard"></i> NIP {{ auth()->user()->nip ?: '-' }}</span>
                    <span><i class="bi bi-briefcase"></i> {{ auth()->user()->jabatan ?: '-' }}</span>
                    <span><i class="bi bi-award"></i> {{ auth()->user()->pangkat_golongan ?: '-' }}</span>
                </div>
            </div>
        </div>
    </section>


    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Total Perjalanan" :value="$travels->total()"
                icon="briefcase-fill" tone="primary" hint="Riwayat tugas Anda" /></div>
        <div class="col-12 col-sm-6 col-lg-4"><x-ui.metric-card label="Perlu Tindakan" :value="$newTaskCount" icon="bell-fill"
                tone="warning" hint="Laporan atau revisi" /></div>
        <div class="col-12 col-lg-4"><x-ui.metric-card label="Status Tanda Tangan" :value="$hasSignature ? 'Tersedia' : 'Belum tersedia'" :icon="$hasSignature ? 'pen-fill' : 'exclamation-triangle-fill'"
                :tone="$hasSignature ? 'success' : 'warning'" hint="Diperlukan untuk cetak laporan" /></div>
    </div>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-clock-history"></i></span> Riwayat
                Perjalanan Dinas</h2>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#employeeHistoryFilters" aria-expanded="{{ array_filter($filters) ? 'true' : 'false' }}"
                aria-controls="employeeHistoryFilters"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <div class="collapse {{ array_filter($filters) ? 'show' : '' }} d-xl-block" id="employeeHistoryFilters">
            <div class="card-body border-bottom">
                <form method="GET" class="row g-3 align-items-end" data-live-filter="employee-dashboard">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="spt" class="form-label">Nomor SPT</label>
                        <input id="spt" name="spt" class="form-control" value="{{ $filters['spt'] ?? '' }}"
                            placeholder="Cari nomor SPT">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Semua</option>
                            @foreach ([\App\Models\PerjalananDinas::STATUS_DRAFT, \App\Models\PerjalananDinas::STATUS_READY, \App\Models\PerjalananDinas::STATUS_PENDING, \App\Models\PerjalananDinas::STATUS_APPROVED, \App\Models\PerjalananDinas::STATUS_REJECTED] as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                    {{ \App\Support\TravelStatus::label($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label for="year" class="form-label">Tahun</label>
                        <select id="year" name="year" class="form-select">
                            <option value="">Semua</option>
                            @foreach ($yearOptions as $year)
                                <option value="{{ $year }}" @selected((string) ($filters['year'] ?? '') === (string) $year)>{{ $year }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="destination" class="form-label">Tujuan</label>
                        <select id="destination" name="destination" class="form-select">
                            <option value="">Semua tujuan</option>
                            @foreach ($destinationOptions as $destination)
                                <option value="{{ $destination }}" @selected(($filters['destination'] ?? '') === $destination)>{{ $destination }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-1 d-grid"><button class="btn btn-primary live-filter-submit"><i
                                class="bi bi-search"></i><span class="visually-hidden">Terapkan filter</span></button></div>
                    <div class="col-6 col-md-3 col-xl-1 d-grid"><a href="{{ route('dashboard.user') }}"
                            data-live-filter-reset
                            class="btn btn-outline-secondary" aria-label="Reset filter"><i
                                class="bi bi-arrow-counterclockwise"></i></a></div>
                </form>
            </div>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead>
                    <tr>
                        <th>No SPT</th>
                        <th>Tujuan & Tanggal</th>
                        <th>Status</th>
                        <th>Tahapan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($travels as $travel)
                        @php
                            $stage = \App\Support\EmployeeTravelStage::for($travel);
                            $currentStep = $stage['step'];
                            $stepLabel = $stage['step_label'];
                            $previewId = 'employee-document-preview-' . $travel->id;
                            $finalTriggerId = 'employee-final-document-trigger-' . $travel->id;
                            $safeSptNumber = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->sptOperationalReference()) ?: 'SPT';
                            $safeEmployeeName =
                                preg_replace('/[^A-Za-z0-9._-]+/', '_', auth()->user()->nama_lengkap) ?: 'Pegawai';
                            $canPrintFinal =
                                $travel->status === \App\Models\PerjalananDinas::STATUS_APPROVED &&
                                auth()->user()->can('printTravelDocument', $travel);
                            $primaryUrl = match ($travel->status) {
                                \App\Models\PerjalananDinas::STATUS_READY => $travel->laporan
                                    ? route('realizations.show', ['id' => $travel->id])
                                    : route('travel-reports.edit', ['travel' => $travel->id]),
                                \App\Models\PerjalananDinas::STATUS_REJECTED => route('realizations.show', [
                                    'id' => $travel->id,
                                ]),
                                default => null,
                            };
                            $primaryDocumentTrigger = $canPrintFinal ? $finalTriggerId : null;
                            $hasPrimaryAction = $primaryUrl || $primaryDocumentTrigger;
                        @endphp
                        <tr @class(['sim-clickable-record' => $hasPrimaryAction])
                            @if ($primaryUrl) data-primary-url="{{ $primaryUrl }}" role="link" tabindex="0"
                                aria-label="Buka proses {{ $travel->sptOperationalReference() }}"
                            @elseif($primaryDocumentTrigger)
                                data-primary-document-trigger="{{ $primaryDocumentTrigger }}" role="button" tabindex="0"
                                aria-label="Pratinjau Dokumen Perjalanan {{ $travel->sptOperationalReference() }}" @endif>
                            <td class="fw-bold text-identity">{{ $travel->sptOperationalReference() }}</td>
                            <td><span class="fw-semibold"><i class="bi bi-geo-alt-fill text-danger"></i>
                                    {{ $travel->kota_tujuan }}</span><br><small
                                    class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}
                                    · {{ $travel->lama_hari }} hari</small></td>
                            <td><x-ui.status-pill :status="$stage['status_key']" :label="$stage['label']" /></td>
                            <td>
                                <div class="journey-progress" aria-label="Tahap {{ $currentStep }} dari 5">
                                    @for ($step = 1; $step <= 5; $step++)
                                        <span
                                            class="journey-progress-step {{ $step < $currentStep ? 'done' : ($step === $currentStep ? 'active' : '') }}"></span>
                                    @endfor
                                </div>
                                <span class="journey-progress-label">{{ $stepLabel }}</span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    @if ($travel->status === \App\Models\PerjalananDinas::STATUS_READY)
                                        @if ($travel->laporan)
                                            <a href="{{ route('realizations.show', ['id' => $travel->id]) }}"
                                                class="btn btn-primary btn-sm"><i
                                                    class="bi bi-arrow-right-circle-fill"></i>
                                                Lanjutkan Realisasi</a>
                                        @else
                                            <a href="{{ route('travel-reports.edit', ['travel' => $travel->id]) }}"
                                                class="btn btn-primary btn-sm"><i class="bi bi-journal-plus"></i> Isi
                                                Laporan</a>
                                        @endif
                                    @elseif($travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED)
                                        <a href="{{ route('realizations.show', ['id' => $travel->id]) }}"
                                            class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i>
                                            Perbaiki Realisasi</a>
                                    @elseif($travel->status === \App\Models\PerjalananDinas::STATUS_APPROVED)
                                        @can('printTravelDocument', $travel)
                                            <button id="{{ $finalTriggerId }}" type="button" class="btn btn-primary btn-sm"
                                                data-document-preview-trigger
                                                data-document-url="{{ route('documents.perjadin', ['id' => $travel->id]) }}"
                                                data-document-label="Dokumen Perjalanan"
                                                data-document-filename="Dokumen_Perjalanan_{{ $safeSptNumber }}.pdf"
                                                aria-controls="{{ $previewId }}" aria-expanded="false"><i
                                                    class="bi bi-printer"></i> Dokumen Perjalanan</button>
                                        @endcan
                                    @else
                                        <button type="button" class="btn btn-light border btn-sm" disabled><i
                                                class="bi bi-hourglass-split"></i> Sedang Diproses</button>
                                    @endif

                                    @if ($travel->laporan)
                                        @if ($hasSignature)
                                            <button type="button" class="btn btn-outline-success btn-sm"
                                                data-document-preview-trigger
                                                data-document-url="{{ route('documents.laporan-perjadin', ['id' => $travel->id]) }}"
                                                data-document-label="Laporan Perjalanan Dinas"
                                                data-document-filename="Laporan_Perjadin_{{ $safeSptNumber }}_{{ $safeEmployeeName }}.pdf"
                                                aria-controls="{{ $previewId }}" aria-expanded="false"><i
                                                    class="bi bi-file-earmark-text"></i> Laporan</button>
                                        @else
                                            <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                                                title="Tanda tangan belum tersedia"><i
                                                    class="bi bi-file-earmark-lock"></i>
                                                Laporan</button>
                                        @endif
                                    @endif
                                    @can('printSuratTugas', $travel)
                                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                            data-document-preview-trigger
                                            data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                                            data-document-label="Surat Perintah Tugas"
                                            data-document-filename="SPT_{{ $safeSptNumber }}.pdf"
                                            aria-controls="{{ $previewId }}" aria-expanded="false"><i
                                                class="bi bi-file-earmark-pdf"></i> SPT</button>
                                    @endcan
                                </div>
                                @if ($travel->laporan && !$hasSignature)
                                    <small class="d-block text-warning-emphasis mt-2"><i class="bi bi-info-circle"></i>
                                        Tanda tangan belum tersedia. Hubungi Admin.</small>
                                @endif
                            </td>
                        </tr>
                        <x-ui.document-preview :id="$previewId" :document-number="$travel->sptOperationalReference()" />
                    @empty
                        <tr>
                            <td colspan="5"><x-ui.empty-state icon="briefcase" :title="array_filter($filters) ? 'Perjalanan tidak ditemukan' : 'Belum ada perjalanan'" :description="array_filter($filters)
                                ? 'Coba ubah atau reset filter pencarian.'
                                : 'Tugas perjalanan dinas baru akan muncul di halaman ini.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($travels->hasPages())
            <div class="card-footer bg-white">{{ $travels->links() }}</div>
        @endif
    </div>
@endsection
