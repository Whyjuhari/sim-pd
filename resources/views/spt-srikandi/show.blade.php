@extends('layouts.app')

@php
    $travel = $workflow->travels->first();
    $previewId = 'srikandi-document-preview-' . $workflow->id;
    $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel?->spt_internal_reference ?? 'SPT') ?: 'SPT';
@endphp

@section('title', 'Kelola SPT - SIM-PD')
@section('brand', 'Kelola SPT')
@section('page-subtitle', 'Periksa draft, unggah dokumen resmi, dan terbitkan SPT untuk pegawai.')
@section('page-actions')
    <a href="{{ route('spt-srikandi.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
@endsection

@section('content')
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}</div>
    @endif

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0 fw-bold text-identity"><i class="bi bi-file-earmark-check"></i> Ringkasan</h6>
            <span class="badge text-bg-light border">{{ $workflow->label() }}</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-muted small">Nomor SPT</div>
                    <div class="fw-semibold">{{ $travel?->no_spt ?? '-' }}</div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Tujuan dan tanggal</div>
                    <div class="fw-semibold">{{ $travel?->kota_tujuan ?? '-' }}</div>
                    @if ($travel?->tgl_berangkat && $travel?->tgl_kembali)
                        <small
                            class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}–{{ $travel->tgl_kembali->format('d/m/Y') }}</small>
                    @endif
                </div>
                <div class="col-12">
                    <div class="text-muted small">Pegawai</div>
                    <div>{{ $workflow->travels->pluck('pegawai.nama_lengkap')->filter()->join(', ') ?: '-' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold text-identity"><i class="bi bi-arrow-right-circle"></i> Tindakan</h6>
        </div>
        <div class="card-body">
            @if ($workflow->status === \App\Models\SptSrikandiWorkflow::STATUS_DRAFT)
                <p class="text-muted">Periksa draft sebelum menandainya telah dikirim ke Srikandi.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary" data-document-preview-trigger
                        data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                        data-document-label="Draft Surat Tugas" data-document-filename="Draft_SPT_{{ $safeReference }}.pdf"
                        aria-controls="{{ $previewId }}" aria-expanded="false">
                        <i class="bi bi-eye"></i> Pratinjau Draft
                    </button>
                    <form method="POST"
                        action="{{ route('spt-srikandi.mark-sent', ['sptGroupId' => $workflow->spt_group_id]) }}"
                        data-sim-confirm data-sim-confirm-title="Tandai sudah dikirim?"
                        data-sim-confirm-text="PDF draft akan diarsipkan dan data SPT tidak dapat diedit lagi."
                        data-sim-confirm-button="Ya, tandai dikirim">
                        @csrf
                        <button class="btn btn-primary"><i class="bi bi-send"></i> Tandai Dikirim</button>
                    </form>
                </div>
            @elseif ($workflow->status === \App\Models\SptSrikandiWorkflow::STATUS_WAITING)
                <form method="POST" enctype="multipart/form-data"
                    action="{{ route('spt-srikandi.upload', ['sptGroupId' => $workflow->spt_group_id]) }}"
                    class="row g-3 align-items-end">
                    @csrf
                    <div class="col-12 col-md">
                        <label for="official_pdf" class="form-label fw-semibold">SPT Resmi</label>
                        <input id="official_pdf" name="official_pdf" type="file" class="form-control"
                            accept="application/pdf,.pdf" required>
                        <div class="form-text">File maksimal 10 MB.</div>
                    </div>
                    <div class="col-12 col-md-auto d-grid">
                        <button class="btn btn-primary"><i class="bi bi-upload"></i> Unggah PDF</button>
                    </div>
                </form>
            @else
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-secondary" data-document-preview-trigger
                        data-document-url="{{ route('spt-srikandi.draft-document', ['sptGroupId' => $workflow->spt_group_id]) }}"
                        data-document-label="Arsip Draft Surat Tugas"
                        data-document-filename="Draft_SPT_{{ $safeReference }}.pdf" aria-controls="{{ $previewId }}"
                        aria-expanded="false">
                        <i class="bi bi-archive"></i> Lihat Draft
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-document-preview-trigger
                        data-document-url="{{ route('spt-srikandi.document', ['sptGroupId' => $workflow->spt_group_id]) }}"
                        data-document-label="SPT Resmi dari Srikandi" data-document-filename="SPT_{{ $safeReference }}.pdf"
                        aria-controls="{{ $previewId }}" aria-expanded="false">
                        <i class="bi bi-file-earmark-pdf"></i> Pratinjau PDF Resmi
                    </button>
                </div>

                @if ($workflow->status === \App\Models\SptSrikandiWorkflow::STATUS_UPLOADED)
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <form method="POST" enctype="multipart/form-data"
                                action="{{ route('spt-srikandi.upload', ['sptGroupId' => $workflow->spt_group_id]) }}"
                                class="row g-2 align-items-end">
                                @csrf
                                <div class="col-md">
                                    <label for="official_pdf" class="form-label">Ganti PDF</label>
                                    <input id="official_pdf" name="official_pdf" type="file" class="form-control"
                                        accept="application/pdf,.pdf" required>
                                </div>
                                <div class="col-md-auto d-grid">
                                    <button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i>
                                        Ganti</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-lg-5 d-flex align-items-end justify-content-lg-end">
                            <form method="POST"
                                action="{{ route('spt-srikandi.publish', ['sptGroupId' => $workflow->spt_group_id]) }}"
                                data-sim-confirm data-sim-confirm-title="Terbitkan SPT resmi?"
                                data-sim-confirm-text="Dokumen akan tersedia untuk seluruh Pegawai dan tidak dapat diganti lagi."
                                data-sim-confirm-button="Ya, terbitkan">
                                @csrf
                                <button class="btn btn-success"><i class="bi bi-check-circle"></i> Terbitkan </button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill me-2"></i>SPT resmi sudah
                        tersedia untuk Pegawai.</div>
                @endif
            @endif
        </div>
    </div>

    <x-ui.document-preview :id="$previewId" :document-number="$travel?->spt_internal_reference ?? 'SPT'" standalone />
@endsection
