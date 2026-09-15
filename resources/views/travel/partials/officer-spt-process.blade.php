@php
    $revisionVersion = $srikandiWorkflow->versions
        ->sortByDesc('version_number')
        ->first(fn($version) => filled($version->revision_reason));
    $groupParams = ['sptGroupId' => $travel->spt_group_id] + $detailContext;
    $needsRevision = $srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_REVISION;
@endphp

<div class="officer-spt-detail" data-officer-spt-process>
    <section class="card mb-3 officer-spt-summary" aria-label="Identitas SPT">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div><span class="small text-muted">No Naskah</span>
                    <div class="fw-bold text-identity">{{ $travel->spt_external_number ?: $travel->no_spt }}</div>
                </div>
                <x-ui.spt-process-status :workflow="$srikandiWorkflow" />
            </div>
            <span class="mb-1">Tujuan : {{ $travel->kota_tujuan }}</span>
            <p class="mb-0">{{ $travel->tgl_berangkat->translatedFormat('d F Y') }} –
                {{ $travel->tgl_kembali->translatedFormat('d F Y') }}</p>
            <span class="small text-muted">{{ $travel->lama_hari }} hari · {{ $travels->count() }} pegawai</span>
        </div>

    </section>

    <section class="card mb-3 spt-process-card" aria-label="Tindakan SPT">
        <div class="card-body">
            @if (in_array(
                    $srikandiWorkflow->status,
                    [\App\Models\SptSrikandiWorkflow::STATUS_DRAFT, \App\Models\SptSrikandiWorkflow::STATUS_REVISION],
                    true))
                @if ($needsRevision && $revisionVersion)
                    <p class="text-warning-emphasis"><strong>Alasan perbaikan:</strong>
                        {{ $revisionVersion->revision_reason }}</p>
                @endif
                <p>{{ $needsRevision ? 'Perbaiki data melalui Rincian SPT di bawah, lalu periksa dan unduh Word.' : 'Periksa draft dengan unduh file Word.' }}
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary" data-document-preview-trigger
                        data-document-url="{{ route('documents.surat-tugas', ['id' => $travel->id]) }}"
                        data-document-label="Konsep Surat Perintah Tugas"
                        data-document-filename="Konsep_SPT_{{ $safeReference }}.pdf"
                        aria-controls="{{ $processPreviewId }}" aria-expanded="false"><i class="bi bi-eye"></i> Lihat
                        Draft</button>
                    <a class="btn {{ $conceptReady ? 'btn-outline-primary' : 'btn-primary' }} d-inline-flex align-items-center"
                        data-spt-concept-download data-download-name="Konsep_SPT_{{ $safeReference }}.docx"
                        href="{{ route('spt-srikandi.concept-document', $groupParams) }}"><i
                            class="bi bi-file-earmark-word"></i> <span class="mx-1">Unduh Draft</span></a>
                </div>
                <p class="small text-muted mt-2 mb-0" data-spt-concept-status aria-live="polite"></p>
                <noscript>
                    <p class="small">Setelah mengunduh Word, <a
                            href="{{ route('travel-orders.show', $groupParams) }}">muat ulang halaman</a> untuk
                        mencatat pengiriman.</p>
                </noscript>
                <form method="POST" action="{{ route('spt-srikandi.mark-sent', $groupParams) }}" data-spt-send-form
                    @class(['mt-3', 'd-none' => !$conceptReady])>
                    @csrf
                    <fieldset data-spt-send-controls @disabled(!$conceptReady)>
                        <div class="form-check mb-2">
                            <input id="confirmed_uploaded" name="confirmed_uploaded" value="1" type="checkbox"
                                class="form-check-input" required>
                            <label for="confirmed_uploaded" class="form-check-label">Saya sudah mengunggah Draft ke
                                SRIKANDI.</label>
                        </div>
                        <button class="btn btn-success"><i class="bi bi-send-check"></i> Sudah Dikirim</button>
                    </fieldset>
                </form>
            @elseif ($srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_WAITING)
                <form method="POST" enctype="multipart/form-data"
                    action="{{ route('spt-srikandi.upload', $groupParams) }}" class="row g-3 align-items-end">
                    @csrf
                    <div class="col-12 col-md">
                        <label for="official_pdf" class="form-label">SPT Resmi</label>
                        <input id="official_pdf" name="official_pdf" type="file" class="form-control"
                            accept="application/pdf,.pdf" required>
                        <div class="form-text">File PDF maksimal 10 MB.</div>
                        @error('official_pdf')
                            <div class="text-danger small">{{ $message }}</div>
                        @enderror
                        <button class="btn btn-primary mt-2"><i class="bi bi-upload"></i>
                            Upload SPT</button>

                    </div>
                </form>
                {{-- @if ($latestProcessVersion)
                    <details class="mt-3" @if ($errors->has('revision_reason')) open @endif>
                        <summary class="text-warning-emphasis">Perlu Diperbaiki</summary>
                        <form method="POST" class="mt-2" action="{{ route('spt-srikandi.revision', $groupParams) }}"
                            data-sim-confirm data-sim-confirm-title="Buka kembali data SPT untuk diperbaiki?"
                            data-sim-confirm-text="Riwayat file Word yang sudah dikirim tetap tersimpan."
                            data-sim-confirm-button="Ya, perbaiki">
                            @csrf
                            <label for="revision_reason" class="form-label">Alasan perbaikan</label>
                            <textarea id="revision_reason" name="revision_reason" class="form-control" rows="3" minlength="5"
                                maxlength="1000" required>{{ old('revision_reason') }}</textarea>
                            @error('revision_reason')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                            <button class="btn btn-warning mt-2">Simpan Alasan</button>
                        </form>
                    </details>
                @endif --}}
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
                <p>Periksa semua data dengan benar sebelum dibagikan.</p>
                <button type="button" class="btn btn-primary" data-document-preview-trigger
                    @if (session('open_official_preview') && !$processComplete) data-spt-open-preview @endif
                    data-document-url="{{ route('spt-srikandi.document', $groupParams) }}"
                    data-document-label="SPT yang Sudah Jadi" data-document-filename="SPT_{{ $safeReference }}.pdf"
                    aria-controls="{{ $processPreviewId }}" aria-expanded="false"><i class="bi bi-eye"></i> Lihat
                    SPT</button>
                @if ($srikandiWorkflow->official_original_name)
                    <p class="small text-muted mt-2 mb-0">{{ $srikandiWorkflow->official_original_name }}</p>
                @endif
            @endif

            <div class="mt-3">
                <x-ui.document-preview :id="$processPreviewId" :document-number="$travel->spt_external_number ?: $travel->no_spt" standalone />
            </div>
            @if ($srikandiWorkflow->status === \App\Models\SptSrikandiWorkflow::STATUS_UPLOADED)
                <div class="spt-share-actions mt-3">
                    <form method="POST" action="{{ route('spt-srikandi.publish', $groupParams) }}" data-sim-confirm
                        data-sim-confirm-title="Bagikan SPT kepada Pegawai?"
                        data-sim-confirm-text="SPT akan tersedia bagi seluruh pegawai dan file tidak dapat diganti lagi."
                        data-sim-confirm-button="Ya, bagikan">
                        @csrf
                        <button class="btn btn-success"><i class="bi bi-people"></i> Bagikan ke Pegawai</button>
                    </form>
                    <details class="mt-2" @if ($errors->has('official_pdf')) open @endif>
                        <summary class="text-primary">Ganti File</summary>
                        <form method="POST" enctype="multipart/form-data"
                            action="{{ route('spt-srikandi.upload', $groupParams) }}"
                            class="row g-2 align-items-end mt-2">
                            @csrf
                            <div class="col-12 col-md">
                                <label for="replacement_pdf" class="form-label">PDF pengganti</label>
                                <input id="replacement_pdf" name="official_pdf" type="file" class="form-control"
                                    accept="application/pdf,.pdf" required>
                                <div class="form-text">Maksimal 10 MB. Periksa kembali PDF setelah diganti.</div>
                                @error('official_pdf')
                                    <div class="text-danger small">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12 col-md-auto d-grid"><button class="btn btn-outline-primary">Ganti
                                    File</button></div>
                        </form>
                    </details>
                </div>
            @endif
        </div>
    </section>

    @include('travel.partials.officer-spt-details')
</div>
