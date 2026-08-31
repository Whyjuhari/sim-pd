@props(['id', 'documentNumber'])

<tr id="{{ $id }}" class="document-preview-row d-none" data-document-preview
    data-document-number="{{ $documentNumber }}">
    <td colspan="5" class="responsive-records-empty">
        <section class="document-preview-panel" aria-label="Pratinjau dokumen {{ $documentNumber }}">
            <div class="document-preview-toolbar">
                <div class="document-preview-heading">
                    <span class="document-preview-eyebrow" data-document-preview-label>Pratinjau Dokumen</span>
                    <strong>{{ $documentNumber }}</strong>
                    <span class="document-preview-status" data-document-preview-status aria-live="polite"></span>
                </div>
                <div class="document-preview-actions">
                    <a class="btn btn-sm btn-outline-primary d-none" href="#" target="_blank" rel="noopener"
                        data-document-preview-open><i class="bi bi-arrows-fullscreen"></i> Buka layar penuh</a>
                    <a class="btn btn-sm btn-outline-primary d-none" href="#" data-document-preview-download><i
                            class="bi bi-download"></i> Unduh PDF</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-document-preview-close><i
                            class="bi bi-x-lg"></i> Tutup</button>
                </div>
            </div>

            <div class="document-preview-loading d-none" data-document-preview-loading role="status">
                <span class="spinner-border text-primary" aria-hidden="true"></span>
                <div><strong>Menyiapkan dokumen...</strong><small> memerlukan beberapa
                        saat.</small></div>
            </div>

            <div class="document-preview-error d-none" data-document-preview-error role="alert">
                <span class="document-preview-error-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                <div class="document-preview-error-copy">
                    <strong>Dokumen belum dapat ditampilkan.</strong>
                    <span data-document-preview-error-message>Silakan coba kembali.</span>
                </div>
                <div class="document-preview-error-actions">
                    <button type="button" class="btn btn-sm btn-primary" data-document-preview-retry><i
                            class="bi bi-arrow-clockwise"></i> Coba lagi</button>
                    <a href="#" target="_blank" rel="noopener" data-document-preview-fallback
                        class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Buka di tab
                        baru</a>
                </div>
            </div>

            <div class="document-preview-viewer d-none" data-document-preview-viewer>
                <iframe data-document-preview-frame title="PDF {{ $documentNumber }}"></iframe>
                <p class="document-preview-fallback mb-0">Jika pratinjau tidak didukung oleh browser, gunakan tombol
                    <strong>Buka layar penuh</strong> atau <strong>Unduh PDF</strong>.
                </p>
            </div>
        </section>
    </td>
</tr>
