@if ($srikandiWorkflow->versions->isNotEmpty())
    <details class="mt-3">
        <summary class="fw-semibold text-identity">Riwayat File Word ({{ $srikandiWorkflow->versions->count() }})</summary>
        <div class="table-responsive mt-2">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Versi</th>
                        <th>Disiapkan</th>
                        <th>Dikirim</th>
                        <th>Catatan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($srikandiWorkflow->versions->sortByDesc('version_number') as $version)
                        <tr>
                            <td class="fw-semibold">V{{ $version->version_number }}</td>
                            <td>
                                {{ $version->prepared_at?->format('d/m/Y H:i') ?? '-' }}
                                @if ($version->preparer)<small class="d-block text-muted">{{ $version->preparer->nama_lengkap }}</small>@endif
                            </td>
                            <td>
                                {{ $version->submitted_at?->format('d/m/Y H:i') ?? 'Belum dikirim' }}
                                @if ($version->submitter)<small class="d-block text-muted">{{ $version->submitter->nama_lengkap }}</small>@endif
                            </td>
                            <td>
                                {{ $version->revision_reason ?: '-' }}
                                @if ($version->revisionRequester)<small class="d-block text-muted">{{ $version->revisionRequester->nama_lengkap }}</small>@endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                    href="{{ route('spt-srikandi.concept-document', ['sptGroupId' => $travel->spt_group_id, 'version' => $version->version_number]) }}">
                                    <i class="bi bi-download"></i> Unduh
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
@if ($srikandiWorkflow->versions->isEmpty() && $srikandiWorkflow->draft_pdf_path)
    <details class="mt-3">
        <summary>Arsip Konsep</summary>
        <button type="button" class="btn btn-outline-secondary mt-2" data-document-preview-trigger
            data-document-url="{{ route('spt-srikandi.draft-document', $groupParams) }}"
            data-document-label="Arsip Konsep SPT" data-document-filename="Konsep_SPT_{{ $safeReference }}.pdf"
            aria-controls="{{ $processPreviewId }}" aria-expanded="false">Lihat Arsip Konsep</button>
    </details>
@endif
