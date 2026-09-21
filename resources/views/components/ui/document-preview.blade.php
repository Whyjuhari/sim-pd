@props(['id', 'documentNumber', 'standalone' => false])

@if ($standalone)
    <div id="{{ $id }}" {{ $attributes->merge(['class' => 'document-preview-standalone d-none']) }}
        data-document-preview data-document-number="{{ $documentNumber }}">
    @else
        <tr id="{{ $id }}" class="document-preview-row d-none" data-document-preview
            data-document-number="{{ $documentNumber }}">
            <td colspan="5" class="responsive-records-empty">
@endif
<section class="document-preview-panel" aria-label="Pratinjau dokumen {{ $documentNumber }}">
    <div class="document-preview-toolbar">
        <div class="document-preview-heading">
            <span class="document-preview-eyebrow" data-document-preview-label>Pratinjau Dokumen</span>
            <strong>{{ $documentNumber }}</strong>
            <span class="document-preview-status" data-document-preview-status aria-live="polite"></span>
        </div>
        <div class="document-preview-actions flex justify-content-center align-items-center">
            <a class="btn btn-sm btn-outline-primary d-none d-inline-flex align-items-center" href="#"
                target="_blank" rel="noopener" data-document-preview-open>
                <i class="bi bi-arrows-fullscreen mx-1"></i>
                <span class="document-preview-open-label-desktop">Buka layar penuh</span>
                <span class="document-preview-open-label-mobile">Layar Penuh</span></a>
            <a class="btn btn-sm btn-outline-primary d-none d-inline-flex align-items-center" href="#"
                data-document-preview-download>
                <i class="bi bi-download"></i>
                <span class="mx-1">
                    Unduh PDF
                </span>
            </a>
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
        <div class="document-preview-mobile-fallback d-none" data-document-preview-mobile role="status">
            <span class="document-preview-mobile-icon" aria-hidden="true"><i class="bi bi-file-earmark-pdf"></i></span>
            <strong>Pratinjau belum dapat ditampilkan</strong>
            <small data-document-preview-mobile-message>Gunakan tombol Buka layar penuh atau Unduh PDF.</small>
        </div>

        <div class="document-pdfjs d-none" data-document-preview-pdfjs>
            <div class="document-pdfjs-toolbar" role="toolbar" aria-label="Kontrol pratinjau PDF">
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-previous
                    aria-label="Halaman sebelumnya" disabled>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </button>
                <span class="document-pdfjs-page-status" data-document-pdfjs-page-status aria-live="polite">0 / 0</span>
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-next
                    aria-label="Halaman berikutnya" disabled>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-zoom-out
                    aria-label="Perkecil dokumen" disabled>
                    <i class="bi bi-dash-lg" aria-hidden="true"></i>
                </button>
                <span class="document-pdfjs-zoom-status" data-document-pdfjs-zoom-status aria-live="polite">0%</span>
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-zoom-in
                    aria-label="Perbesar dokumen" disabled>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                </button>
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-fit-width
                    aria-label="Sesuaikan lebar dokumen" title="Sesuaikan lebar" disabled>
                    <i class="bi bi-arrows-expand" aria-hidden="true"></i>
                </button>
                <button type="button" class="document-pdfjs-tool" data-document-pdfjs-print aria-label="Cetak dokumen"
                    title="Cetak" disabled>
                    <i class="bi bi-printer" aria-hidden="true"></i>
                </button>
            </div>

            <div class="document-pdfjs-loading" data-document-pdfjs-loading role="status">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span>Menyiapkan halaman...</span>
            </div>

            <div class="document-pdfjs-pages" data-document-pdfjs-pages tabindex="0" aria-label="Halaman dokumen PDF">
                <section class="document-pdfjs-page" data-document-pdfjs-page>
                    <canvas class="document-pdfjs-canvas" data-document-pdfjs-canvas role="img"
                        aria-label="Isi halaman PDF"></canvas>
                    <span class="document-pdfjs-page-loading d-none" data-document-pdfjs-page-loading>
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    </span>
                </section>
            </div>
        </div>

        <iframe data-document-preview-frame title="PDF {{ $documentNumber }}"></iframe>
        <p class="document-preview-fallback mb-0">Jika pratinjau tidak didukung, gunakan tombol
            <strong>Buka layar penuh</strong> atau <strong>Unduh PDF</strong>.
        </p>
    </div>

    @isset($footer)
        <div class="document-preview-footer d-none" data-document-preview-footer>
            {{ $footer }}
        </div>
    @endisset
</section>
@if ($standalone)
    </div>
@else
    </td>
    </tr>
@endif
