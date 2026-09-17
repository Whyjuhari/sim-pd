@php
    $revisionVersion = $srikandiWorkflow->versions
        ->sortByDesc('version_number')
        ->first(fn($version) => filled($version->revision_reason));
    $groupParams = ['sptGroupId' => $travel->spt_group_id] + $detailContext;
    $needsRevision = $srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_REVISION;
    $uploadFormReady = ($conceptReady ?? false)
        || $srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_WAITING;
@endphp

<div class="officer-spt-detail" data-officer-spt-process>
    <section class="card mb-3 officer-spt-summary" aria-label="Identitas SPT">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0 fw-bold text-identity"><i class="bi bi-file-earmark-check"></i> Ringkasan</h6>
            <x-ui.spt-process-status :workflow="$srikandiWorkflow" />
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-muted small">Nomor SPT</div>
                    <div class="fw-semibold">{{ $travel->spt_external_number ?: $travel->no_spt }}</div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Tujuan dan tanggal</div>
                    <div class="fw-semibold">{{ $travel->kota_tujuan }}</div>
                    <small class="text-muted">{{ $travel->tgl_berangkat->translatedFormat('d/m/Y') }} –
                        {{ $travel->tgl_kembali->translatedFormat('d/m/Y') }}</small> <br>
                    <span class="small text-muted">{{ $travel->lama_hari }} hari · {{ $travels->count() }}
                        pegawai</span>
                </div>
            </div>
        </div>
    </section>

    <section class="card mb-3 spt-process-card" aria-label="Tindakan SPT">
        <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold text-identity"><i class="bi bi-arrow-right-circle"></i> Tindakan</h6>
        </div>
        <div class="card-body">
            @if (in_array(
                    $srikandiWorkflow->status,
                    [\App\Models\SptSrikandiWorkflow::STATUS_DRAFT, \App\Models\SptSrikandiWorkflow::STATUS_REVISION, \App\Models\SptSrikandiWorkflow::STATUS_WAITING],
                    true))
                @if ($needsRevision && $revisionVersion)
                    <p class="text-warning-emphasis mb-3"><strong>Alasan perbaikan:</strong>
                        {{ $revisionVersion->revision_reason }}</p>
                @endif
                <div class="row g-3 align-items-stretch">
                     <div class="col-12 col-md-6 d-flex flex-column justify-content-between">
                         <div>
                             <p class="mb-2">{{ $needsRevision ? 'Perbaiki data melalui Rincian SPT di bawah, lalu periksa dan unduh Word.' : 'Periksa draft dengan unduh file Word.' }}</p>
                             <div class="d-flex flex-wrap gap-2">
                                 <button type="button" class="btn btn-outline-secondary" data-document-preview-trigger
                                     data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                                     data-document-label="Konsep Surat Perintah Tugas"
                                     data-document-filename="Konsep_SPT_{{ $safeReference }}.pdf"
                                     aria-controls="{{ $processPreviewId }}" aria-expanded="false"><i class="bi bi-archive"></i>
                                     Lihat
                                     Draft</button>
                                 <a class="btn btn-outline-primary d-inline-flex align-items-center"
                                     data-spt-concept-download data-download-name="Konsep_SPT_{{ $safeReference }}.docx"
                                     href="{{ route('spt-srikandi.concept-document', $groupParams) }}"><i
                                         class="bi bi-file-word-fill"></i> <span class="mx-1">Unduh Draft</span></a>
                             </div>
                         </div>
                         <p class="small text-muted mt-2 mb-0" data-spt-concept-status aria-live="polite"></p>
                     </div>
                     <div class="col-12 col-md-6 border-start-md ps-md-3">
                         <form method="POST" enctype="multipart/form-data"
                             action="{{ route('spt-srikandi.upload', $groupParams) }}" class="h-100 d-flex flex-column justify-content-between @if (! $uploadFormReady && ! $errors->has('official_pdf')) d-none @endif"
                             data-spt-official-upload-form>
                             @csrf
                             <div>
                                 <label for="official_pdf" class="form-label fw-semibold">SPT Resmi</label>
                                 <input id="official_pdf" name="official_pdf" type="file" class="form-control"
                                     accept="application/pdf,.pdf" required>
                                 <div class="form-text">File PDF maksimal 10 MB.</div>
                                 @error('official_pdf')
                                     <div class="text-danger small">{{ $message }}</div>
                                 @enderror
                             </div>
                             <div class="mt-2">
                                 <button class="btn btn-primary"><i class="bi bi-upload"></i>
                                     Upload SPT</button>
                             </div>
                         </form>
                     </div>
                </div>
            @else
                @if ($processComplete)
                    <p class="small text-muted">
                        @if ($srikandiWorkflow->published_at)
                            Dibagikan pada {{ $srikandiWorkflow->published_at->translatedFormat('d F Y, H:i') }}
                        @endif
                        @if ($srikandiWorkflow->publisher)
                            oleh {{ $srikandiWorkflow->publisher->nama_lengkap }}
                        @endif.
                    </p>
                @endif
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary" data-document-preview-trigger
                        @if (session('open_official_preview') && !$processComplete) data-spt-open-preview @endif
                        data-document-url="{{ route('spt-srikandi.document', $groupParams) }}"
                        data-document-label="SPT yang Sudah Jadi" data-document-filename="SPT_{{ $safeReference }}.pdf"
                        aria-controls="{{ $processPreviewId }}" aria-expanded="false">
                        <i class="bi bi-file-earmark-pdf"></i>
                        Lihat SPT
                    </button>

                    @if ($srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_UPLOADED)
                        <form method="POST" action="{{ route('spt-srikandi.publish', $groupParams) }}" class="m-0"
                            data-sim-confirm data-sim-confirm-title="Bagikan SPT kepada Pegawai?"
                            data-sim-confirm-text="SPT akan tersedia bagi seluruh pegawai dan file tidak dapat diganti lagi."
                            data-sim-confirm-button="Ya, bagikan">
                            @csrf

                            <button class="btn btn-success">
                                <i class="bi bi-people"></i>
                                Bagikan ke Pegawai
                            </button>
                        </form>
                    @endif
                </div>
                @if ($srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_UPLOADED)
                    <details class="spt-replace-file" @if ($errors->has('official_pdf')) open @endif>
                        <summary class="text-primary">Ganti File</summary>

                        <form method="POST" enctype="multipart/form-data"
                            action="{{ route('spt-srikandi.upload', $groupParams) }}" class="mt-2">
                            @csrf

                            <label for="replacement_pdf" class="form-label">
                                Pilih File SPT
                            </label>

                            <div class="row g-2 align-items-stretch">
                                <div class="col-12 col-md">
                                    <input id="replacement_pdf" name="official_pdf" type="file" class="form-control"
                                        accept="application/pdf,.pdf" required>
                                </div>

                                <div class="col-12 col-md-auto d-grid">
                                    <button class="btn btn-identity">
                                        <i class="bi bi-cloud-arrow-up-fill"></i> Unggah
                                    </button>
                                </div>
                            </div>

                            <div class="form-text">
                                Maksimal 10 MB. Periksa kembali PDF setelah diganti.
                            </div>

                            @error('official_pdf')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </form>
                    </details>
                @endif
            @endif

            <x-ui.document-preview :id="$processPreviewId" :document-number="$travel->spt_external_number ?: $travel->no_spt" standalone class="mt-3" />

        </div>

    </section>

    @include('travel.partials.officer-spt-details')
</div>
