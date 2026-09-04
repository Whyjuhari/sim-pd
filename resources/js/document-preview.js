import pdfWorkerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

const previewTriggers = Array.from(document.querySelectorAll('[data-document-preview-trigger]'));
const previewPanels = Array.from(document.querySelectorAll('[data-document-preview]'));

if (previewTriggers.length > 0) {
    const TABLET_MAX_WIDTH = 1199.98;
    const ZOOM_MIN = 0.25;
    const ZOOM_MAX = 2.5;
    const ZOOM_STEP = 0.1;

    let activePanel = null;
    let activeTrigger = null;
    let activeRecord = null;
    let activeObjectUrl = null;
    let activeBlob = null;
    let activeFilename = 'Dokumen.pdf';
    let activeViewerMode = null;
    let isLoading = false;
    let requestVersion = 0;
    let viewerVersion = 0;
    let focusAfterLoading = null;
    let resizeTimer = null;
    let pdfJsModulePromise = null;
    let pdfLoadingTask = null;
    let pdfDocument = null;
    let pdfRenderTask = null;
    let pdfRenderPromise = null;
    let pdfCurrentPage = 1;
    let pdfTotalPages = 0;
    let pdfScale = 1;
    let pdfFitWidth = true;
    const pdfPageCache = new Map();

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const tabletViewport = window.matchMedia(`(max-width: ${TABLET_MAX_WIDTH}px)`);
    const userAgent = navigator.userAgent ?? '';
    const isMobileOrTabletDevice = navigator.userAgentData?.mobile === true
        || /Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i.test(userAgent)
        || (/Macintosh/i.test(userAgent) && navigator.maxTouchPoints > 1);

    const panelPart = (panel, selector) => panel?.querySelector(selector);
    const usesPdfJsViewer = () => tabletViewport.matches || isMobileOrTabletDevice;
    const getPdfJs = async () => {
        if (!pdfJsModulePromise) {
            pdfJsModulePromise = import('pdfjs-dist/legacy/build/pdf.mjs').then((pdfJs) => {
                pdfJs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
                return pdfJs;
            });
        }

        return pdfJsModulePromise;
    };

    const setTriggerAvailability = (disabled) => {
        previewTriggers.forEach((trigger) => {
            trigger.disabled = disabled;
            trigger.setAttribute('aria-disabled', String(disabled));
        });
    };

    const revokeObjectUrl = () => {
        if (activeObjectUrl) {
            URL.revokeObjectURL(activeObjectUrl);
            activeObjectUrl = null;
        }
    };

    const setOuterDocumentActions = (panel, visible) => {
        panelPart(panel, '[data-document-preview-open]')?.classList.toggle('d-none', !visible);
        panelPart(panel, '[data-document-preview-download]')?.classList.toggle('d-none', !visible);
    };

    const resetPdfJsControls = (panel) => {
        [
            '[data-document-pdfjs-previous]',
            '[data-document-pdfjs-next]',
            '[data-document-pdfjs-zoom-out]',
            '[data-document-pdfjs-zoom-in]',
            '[data-document-pdfjs-fit-width]',
            '[data-document-pdfjs-print]',
        ].forEach((selector) => {
            const control = panelPart(panel, selector);
            if (control) control.disabled = true;
        });

        const pageStatus = panelPart(panel, '[data-document-pdfjs-page-status]');
        const zoomStatus = panelPart(panel, '[data-document-pdfjs-zoom-status]');
        const fitWidth = panelPart(panel, '[data-document-pdfjs-fit-width]');

        if (pageStatus) pageStatus.textContent = '0 / 0';
        if (zoomStatus) zoomStatus.textContent = '0%';
        fitWidth?.classList.remove('is-active');
    };

    const updatePdfJsControls = (panel) => {
        const ready = Boolean(pdfDocument && pdfTotalPages > 0);
        const previous = panelPart(panel, '[data-document-pdfjs-previous]');
        const next = panelPart(panel, '[data-document-pdfjs-next]');
        const zoomOut = panelPart(panel, '[data-document-pdfjs-zoom-out]');
        const zoomIn = panelPart(panel, '[data-document-pdfjs-zoom-in]');
        const fitWidth = panelPart(panel, '[data-document-pdfjs-fit-width]');
        const print = panelPart(panel, '[data-document-pdfjs-print]');
        const pageStatus = panelPart(panel, '[data-document-pdfjs-page-status]');
        const zoomStatus = panelPart(panel, '[data-document-pdfjs-zoom-status]');

        if (previous) previous.disabled = !ready || pdfCurrentPage <= 1;
        if (next) next.disabled = !ready || pdfCurrentPage >= pdfTotalPages;
        if (zoomOut) zoomOut.disabled = !ready || pdfScale <= ZOOM_MIN;
        if (zoomIn) zoomIn.disabled = !ready || pdfScale >= ZOOM_MAX;
        if (fitWidth) fitWidth.disabled = !ready;
        if (print) print.disabled = !ready;
        if (pageStatus) pageStatus.textContent = ready ? `${pdfCurrentPage} / ${pdfTotalPages}` : '0 / 0';
        if (zoomStatus) zoomStatus.textContent = ready ? `${Math.round(pdfScale * 100)}%` : '0%';
        fitWidth?.classList.toggle('is-active', ready && pdfFitWidth);
    };

    const resetPdfJsMarkup = (panel) => {
        const canvas = panelPart(panel, '[data-document-pdfjs-canvas]');
        const page = panelPart(panel, '[data-document-pdfjs-page]');
        const pages = panelPart(panel, '[data-document-pdfjs-pages]');

        panelPart(panel, '[data-document-preview-pdfjs]')?.classList.add('d-none');
        panelPart(panel, '[data-document-pdfjs-loading]')?.classList.remove('d-none');
        panelPart(panel, '[data-document-pdfjs-page-loading]')?.classList.add('d-none');
        if (canvas) {
            canvas.width = 1;
            canvas.height = 1;
            canvas.removeAttribute('style');
        }
        page?.removeAttribute('style');
        if (pages) {
            pages.scrollTop = 0;
            pages.scrollLeft = 0;
        }
        resetPdfJsControls(panel);
    };

    const destroyPdfJs = (panel = activePanel) => {
        viewerVersion += 1;
        pdfRenderTask?.cancel();
        pdfRenderTask = null;
        pdfRenderPromise = null;
        pdfPageCache.clear();

        const loadingTask = pdfLoadingTask;
        pdfLoadingTask = null;
        pdfDocument = null;
        pdfCurrentPage = 1;
        pdfTotalPages = 0;
        pdfScale = 1;
        pdfFitWidth = true;

        if (loadingTask) Promise.resolve(loadingTask.destroy()).catch(() => {});
        if (panel) resetPdfJsMarkup(panel);
    };

    const resetPanel = (panel) => {
        const frame = panelPart(panel, '[data-document-preview-frame]');
        const mobileFallback = panelPart(panel, '[data-document-preview-mobile]');
        const openLink = panelPart(panel, '[data-document-preview-open]');
        const downloadLink = panelPart(panel, '[data-document-preview-download]');
        const fallbackLink = panelPart(panel, '[data-document-preview-fallback]');

        if (frame) {
            frame.removeAttribute('src');
            frame.classList.remove('d-none');
        }
        mobileFallback?.classList.add('d-none');
        resetPdfJsMarkup(panel);
        if (openLink) openLink.removeAttribute('href');
        if (downloadLink) {
            downloadLink.removeAttribute('href');
            downloadLink.removeAttribute('download');
        }
        if (fallbackLink) fallbackLink.href = '#';

        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-footer]')?.classList.add('d-none');
        setOuterDocumentActions(panel, false);

        const status = panelPart(panel, '[data-document-preview-status]');
        if (status) status.textContent = '';
        activeViewerMode = null;
    };

    const closePreview = ({ restoreFocus = true } = {}) => {
        if (!activePanel) return;
        const panel = activePanel;
        const trigger = activeTrigger;

        requestVersion += 1;
        destroyPdfJs(panel);
        revokeObjectUrl();
        activeBlob = null;
        resetPanel(panel);
        panel.classList.add('d-none');
        trigger?.setAttribute('aria-expanded', 'false');
        activeRecord?.classList.remove('document-preview-owner');
        activePanel = null;
        activeTrigger = null;
        activeRecord = null;
        panel.dispatchEvent(new CustomEvent('document-preview:closed'));

        if (restoreFocus && trigger && !trigger.disabled) {
            trigger.focus({ preventScroll: true });
        } else if (restoreFocus && trigger) {
            focusAfterLoading = trigger;
        }
    };

    const errorMessageFor = (status) => {
        if (status === 401 || status === 419) return 'Sesi Anda telah berakhir. Muat ulang halaman lalu masuk kembali.';
        if (status === 403) return 'Anda tidak memiliki izin untuk mencetak dokumen ini.';
        if (status === 404) return 'Data dokumen tidak ditemukan.';
        if (status === 422) return 'Data dokumen belum lengkap untuk dicetak.';
        return 'Pembuatan PDF gagal. Silakan coba kembali atau hubungi administrator.';
    };

    const responseErrorMessage = async (response) => {
        const contentType = (response.headers.get('content-type') ?? '').toLowerCase();

        if (contentType.includes('application/json')) {
            try {
                const payload = await response.json();
                const validationMessage = Object.values(payload.errors ?? {})
                    .flat()
                    .find((message) => typeof message === 'string' && message.trim() !== '');

                if (validationMessage) return validationMessage;
                if (typeof payload.message === 'string' && payload.message.trim() !== '') {
                    return payload.message;
                }
            } catch {
                // Gunakan pesan aman berdasarkan status jika respons JSON rusak.
            }
        }

        return errorMessageFor(response.status);
    };

    const preparePanel = (panel, trigger) => {
        const documentLabel = trigger.dataset.documentLabel ?? 'Dokumen';
        const documentNumber = panel.dataset.documentNumber ?? '';
        const label = panelPart(panel, '[data-document-preview-label]');
        const frame = panelPart(panel, '[data-document-preview-frame]');
        const fallbackLink = panelPart(panel, '[data-document-preview-fallback]');
        const hasGetFallback = !trigger.dataset.documentForm;

        if (label) label.textContent = `Pratinjau ${documentLabel}`;
        if (frame) frame.title = `PDF ${documentLabel}${documentNumber ? ` ${documentNumber}` : ''}`;
        if (fallbackLink) {
            fallbackLink.classList.toggle('d-none', !hasGetFallback);
            fallbackLink.href = hasGetFallback ? (trigger.dataset.documentUrl ?? '#') : '#';
        }
    };

    const showLoading = (panel, trigger) => {
        resetPanel(panel);
        preparePanel(panel, trigger);
        panelPart(panel, '[data-document-preview-loading]')?.classList.remove('d-none');
        const status = panelPart(panel, '[data-document-preview-status]');
        if (status) status.textContent = 'Menyiapkan dokumen...';
        panel.dispatchEvent(new CustomEvent('document-preview:loading'));
    };

    const showError = (panel, message) => {
        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.remove('d-none');
        const status = panelPart(panel, '[data-document-preview-status]');
        const errorMessage = panelPart(panel, '[data-document-preview-error-message]');
        if (status) status.textContent = 'Gagal memuat dokumen';
        if (errorMessage) errorMessage.textContent = message;
        panel.dispatchEvent(new CustomEvent('document-preview:error', {
            detail: { message },
        }));
    };

    const showPdfJsFallback = (panel, message) => {
        destroyPdfJs(panel);
        activeViewerMode = 'fallback';
        panelPart(panel, '[data-document-preview-frame]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-pdfjs]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-mobile]')?.classList.remove('d-none');
        setOuterDocumentActions(panel, true);

        const fallbackMessage = panelPart(panel, '[data-document-preview-mobile-message]');
        const status = panelPart(panel, '[data-document-preview-status]');
        if (fallbackMessage) fallbackMessage.textContent = message;
        if (status) status.textContent = 'Gunakan pilihan buka atau unduh';
    };

    const getCachedPdfPage = async (pageNumber) => {
        if (!pdfPageCache.has(pageNumber)) pdfPageCache.set(pageNumber, pdfDocument.getPage(pageNumber));
        return pdfPageCache.get(pageNumber);
    };

    const fitScaleFor = (panel, page) => {
        const pages = panelPart(panel, '[data-document-pdfjs-pages]');
        const baseViewport = page.getViewport({ scale: 1 });
        const availableWidth = Math.max(220, (pages?.clientWidth ?? 252) - 16);
        return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, availableWidth / baseViewport.width));
    };

    const renderCurrentPdfPage = async (panel, { resetScroll = false } = {}) => {
        if (!pdfDocument || activeViewerMode !== 'pdfjs') return;
        const page = await getCachedPdfPage(pdfCurrentPage);
        const canvas = panelPart(panel, '[data-document-pdfjs-canvas]');
        const pageElement = panelPart(panel, '[data-document-pdfjs-page]');
        const pages = panelPart(panel, '[data-document-pdfjs-pages]');
        const loading = panelPart(panel, '[data-document-pdfjs-page-loading]');
        if (!canvas || !pageElement || !pages) return;

        if (pdfFitWidth) pdfScale = fitScaleFor(panel, page);
        pdfScale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, pdfScale));
        updatePdfJsControls(panel);

        if (pdfRenderPromise) {
            pdfRenderTask?.cancel();
            try {
                await pdfRenderPromise;
            } catch {
                // Pembatalan render memang diharapkan saat halaman atau zoom berubah.
            }
        }

        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
        const cssViewport = page.getViewport({ scale: pdfScale });
        const renderViewport = page.getViewport({ scale: pdfScale * outputScale });

        loading?.classList.remove('d-none');
        canvas.width = Math.max(1, Math.floor(renderViewport.width));
        canvas.height = Math.max(1, Math.floor(renderViewport.height));
        canvas.style.width = `${Math.max(1, Math.floor(cssViewport.width))}px`;
        canvas.style.height = `${Math.max(1, Math.floor(cssViewport.height))}px`;
        pageElement.style.width = `${Math.max(1, Math.floor(cssViewport.width))}px`;
        pageElement.style.height = `${Math.max(1, Math.floor(cssViewport.height))}px`;
        canvas.setAttribute('aria-label', `Isi halaman ${pdfCurrentPage} dari ${pdfTotalPages}`);
        pageElement.setAttribute('aria-label', `Halaman ${pdfCurrentPage} dari ${pdfTotalPages}`);

        const renderTask = page.render({ canvas, viewport: renderViewport });
        pdfRenderTask = renderTask;
        pdfRenderPromise = renderTask.promise
            .then(() => loading?.classList.add('d-none'))
            .catch((error) => {
                if (error?.name !== 'RenderingCancelledException') throw error;
            })
            .finally(() => {
                if (pdfRenderTask === renderTask) {
                    pdfRenderTask = null;
                    pdfRenderPromise = null;
                }
            });

        await pdfRenderPromise;
        if (resetScroll) {
            pages.scrollTop = 0;
            pages.scrollLeft = 0;
        }
    };

    const startPdfJsViewer = async (panel) => {
        const version = ++viewerVersion;
        const container = panelPart(panel, '[data-document-preview-pdfjs]');
        const loading = panelPart(panel, '[data-document-pdfjs-loading]');
        const status = panelPart(panel, '[data-document-preview-status]');

        container?.classList.remove('d-none');
        loading?.classList.remove('d-none');
        if (status) status.textContent = 'Menyiapkan pratinjau untuk perangkat ini...';

        try {
            const [pdfJs, bytes] = await Promise.all([
                getPdfJs(),
                activeBlob.arrayBuffer().then((buffer) => new Uint8Array(buffer)),
            ]);
            if (version !== viewerVersion || panel !== activePanel || activeViewerMode !== 'pdfjs') return;

            const loadingTask = pdfJs.getDocument({ data: bytes });
            pdfLoadingTask = loadingTask;
            const loadedDocument = await loadingTask.promise;
            if (version !== viewerVersion || panel !== activePanel || activeViewerMode !== 'pdfjs') {
                await loadingTask.destroy();
                return;
            }

            pdfDocument = loadedDocument;
            pdfTotalPages = loadedDocument.numPages;
            pdfCurrentPage = 1;
            pdfFitWidth = true;
            loading?.classList.add('d-none');
            await renderCurrentPdfPage(panel, { resetScroll: true });
            if (status) status.textContent = `Dokumen siap · ${pdfTotalPages} halaman`;
        } catch {
            if (version === viewerVersion && panel === activePanel) {
                showPdfJsFallback(panel, 'PDF tidak dapat dirender pada perangkat ini. Gunakan tombol buka atau unduh.');
            }
        }
    };

    const applyViewerMode = (panel, { force = false } = {}) => {
        if (!panel || !activeBlob || !activeObjectUrl) return;
        const nextMode = usesPdfJsViewer() ? 'pdfjs' : 'iframe';
        if (!force && activeViewerMode === nextMode) return;

        const frame = panelPart(panel, '[data-document-preview-frame]');
        const pdfJsContainer = panelPart(panel, '[data-document-preview-pdfjs]');
        const mobileFallback = panelPart(panel, '[data-document-preview-mobile]');
        const status = panelPart(panel, '[data-document-preview-status]');

        destroyPdfJs(panel);
        activeViewerMode = nextMode;
        mobileFallback?.classList.add('d-none');
        setOuterDocumentActions(panel, true);

        if (nextMode === 'pdfjs') {
            frame?.classList.add('d-none');
            frame?.removeAttribute('src');
            pdfJsContainer?.classList.remove('d-none');
            startPdfJsViewer(panel);
            return;
        }

        pdfJsContainer?.classList.add('d-none');
        frame?.classList.remove('d-none');
        if (frame && frame.src !== activeObjectUrl) frame.src = activeObjectUrl;
        if (status) status.textContent = 'Dokumen siap';
    };

    const showDocument = (panel, objectUrl, filename, blob) => {
        const openLink = panelPart(panel, '[data-document-preview-open]');
        const downloadLink = panelPart(panel, '[data-document-preview-download]');

        activeBlob = blob;
        activeFilename = filename;
        if (openLink) openLink.href = objectUrl;
        if (downloadLink) {
            downloadLink.href = objectUrl;
            downloadLink.download = filename;
        }

        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.remove('d-none');
        panelPart(panel, '[data-document-preview-footer]')?.classList.remove('d-none');
        applyViewerMode(panel, { force: true });
        panel.dispatchEvent(new CustomEvent('document-preview:ready'));
        panel.scrollIntoView({
            behavior: prefersReducedMotion.matches ? 'auto' : 'smooth',
            block: 'start',
        });
    };

    const goToPdfPage = async (panel, pageNumber) => {
        if (!pdfDocument) return;
        pdfCurrentPage = Math.min(pdfTotalPages, Math.max(1, pageNumber));
        updatePdfJsControls(panel);
        await renderCurrentPdfPage(panel, { resetScroll: true });
    };

    const changePdfZoom = async (panel, direction) => {
        if (!pdfDocument) return;
        pdfFitWidth = false;
        pdfScale = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, pdfScale + (direction * ZOOM_STEP)));
        updatePdfJsControls(panel);
        await renderCurrentPdfPage(panel);
    };

    const fitPdfToWidth = async (panel) => {
        if (!pdfDocument) return;
        pdfFitWidth = true;
        await renderCurrentPdfPage(panel, { resetScroll: true });
    };

    const togglePdfFullscreen = async (panel) => {
        const target = panelPart(panel, '.document-preview-panel');
        if (!target) return;

        try {
            if (document.fullscreenElement === target) {
                await document.exitFullscreen();
            } else if (target.requestFullscreen) {
                await target.requestFullscreen();
            } else {
                throw new Error('Fullscreen API tidak tersedia.');
            }
        } catch {
            const opened = window.open(activeObjectUrl, '_blank');
            if (opened) opened.opener = null;
            if (!opened) {
                const status = panelPart(panel, '[data-document-preview-status]');
                if (status) status.textContent = 'Layar penuh diblokir browser. Gunakan Unduh PDF.';
            }
        }
    };

    const printPdfJsDocument = async (panel) => {
        if (!pdfDocument) return;
        const printButton = panelPart(panel, '[data-document-pdfjs-print]');
        const status = panelPart(panel, '[data-document-preview-status]');
        const printRoot = document.createElement('div');
        printRoot.className = 'document-pdfjs-print-root';
        if (printButton) printButton.disabled = true;
        if (status) status.textContent = 'Menyiapkan seluruh halaman untuk dicetak...';

        try {
            for (let pageNumber = 1; pageNumber <= pdfTotalPages; pageNumber += 1) {
                const page = await getCachedPdfPage(pageNumber);
                const viewport = page.getViewport({ scale: 1.5 });
                const canvas = document.createElement('canvas');
                const image = document.createElement('img');
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                await page.render({ canvas, viewport, intent: 'print' }).promise;
                image.src = canvas.toDataURL('image/png');
                image.alt = `Halaman ${pageNumber}`;
                printRoot.append(image);
                canvas.width = 1;
                canvas.height = 1;
            }

            document.body.append(printRoot);
            document.body.classList.add('document-preview-printing');
            let cleaned = false;
            const cleanup = () => {
                if (cleaned) return;
                cleaned = true;
                document.body.classList.remove('document-preview-printing');
                printRoot.remove();
                if (panel === activePanel) {
                    updatePdfJsControls(panel);
                    if (status) status.textContent = `Dokumen siap · ${pdfTotalPages} halaman`;
                }
            };

            window.addEventListener('afterprint', cleanup, { once: true });
            setTimeout(cleanup, 60000);
            window.print();
        } catch {
            printRoot.remove();
            if (printButton) printButton.disabled = false;
            if (status) status.textContent = 'Cetak langsung tidak tersedia. PDF dibuka pada layar penuh.';
            const opened = window.open(activeObjectUrl, '_blank');
            if (opened) opened.opener = null;
        }
    };

    const loadPreview = async (trigger, { force = false } = {}) => {
        if (isLoading) return;
        const panelId = trigger.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        const documentUrl = trigger.dataset.documentUrl;
        if (!panel || !documentUrl) return;

        const formId = trigger.dataset.documentForm;
        const requestForm = formId ? document.getElementById(formId) : null;
        if (formId && !(requestForm instanceof HTMLFormElement)) return;
        if (requestForm instanceof HTMLFormElement && !requestForm.checkValidity()) {
            requestForm.reportValidity();
            return;
        }

        if (activePanel === panel && activeTrigger === trigger && !force) {
            closePreview();
            return;
        }
        if (activePanel && (activePanel !== panel || activeTrigger !== trigger)) {
            closePreview({ restoreFocus: false });
        } else if (force) {
            destroyPdfJs(panel);
            revokeObjectUrl();
            activeBlob = null;
        }

        activePanel = panel;
        activeTrigger = trigger;
        activeRecord = trigger.closest('tr');
        activeRecord?.classList.add('document-preview-owner');
        panel.classList.remove('d-none');
        trigger.setAttribute('aria-expanded', 'true');
        showLoading(panel, trigger);
        isLoading = true;
        setTriggerAvailability(true);
        const currentRequest = ++requestVersion;

        try {
            const formData = requestForm instanceof HTMLFormElement
                ? new FormData(requestForm)
                : null;
            formData?.delete('_method');
            const response = await fetch(documentUrl, {
                method: formData ? 'POST' : 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: formData ? 'application/json, application/pdf' : 'application/pdf',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });
            const contentType = (response.headers.get('content-type') ?? '').toLowerCase();
            if (!response.ok) throw new Error(await responseErrorMessage(response));
            if (!contentType.includes('application/pdf')) {
                throw new Error(response.redirected
                    ? 'Sesi login mungkin telah berakhir. Muat ulang halaman lalu masuk kembali.'
                    : 'Server tidak mengirim dokumen PDF yang valid.');
            }

            const blob = await response.blob();
            if (blob.size === 0) throw new Error('Dokumen PDF yang diterima kosong. Silakan coba kembali.');
            const objectUrl = URL.createObjectURL(blob);
            if (currentRequest !== requestVersion || activePanel !== panel || activeTrigger !== trigger) {
                URL.revokeObjectURL(objectUrl);
                return;
            }

            revokeObjectUrl();
            activeObjectUrl = objectUrl;
            showDocument(panel, objectUrl, trigger.dataset.documentFilename ?? 'Dokumen.pdf', blob);
        } catch (error) {
            if (currentRequest === requestVersion && activePanel === panel && activeTrigger === trigger) {
                showError(panel, error instanceof Error ? error.message : errorMessageFor(500));
            }
        } finally {
            isLoading = false;
            setTriggerAvailability(false);
            if (focusAfterLoading) {
                focusAfterLoading.focus({ preventScroll: true });
                focusAfterLoading = null;
            }
        }
    };

    previewTriggers.forEach((trigger) => trigger.addEventListener('click', () => loadPreview(trigger)));
    previewPanels.forEach((panel) => {
        panelPart(panel, '[data-document-preview-close]')?.addEventListener('click', () => {
            if (panel === activePanel) closePreview();
        });
        panelPart(panel, '[data-document-preview-retry]')?.addEventListener('click', () => {
            if (panel === activePanel && activeTrigger) loadPreview(activeTrigger, { force: true });
        });
        panelPart(panel, '[data-document-preview-open]')?.addEventListener('click', (event) => {
            if (panel === activePanel && activeViewerMode === 'pdfjs') {
                event.preventDefault();
                togglePdfFullscreen(panel);
            }
        });
        panelPart(panel, '[data-document-pdfjs-previous]')?.addEventListener('click', () => {
            if (panel === activePanel) goToPdfPage(panel, pdfCurrentPage - 1);
        });
        panelPart(panel, '[data-document-pdfjs-next]')?.addEventListener('click', () => {
            if (panel === activePanel) goToPdfPage(panel, pdfCurrentPage + 1);
        });
        panelPart(panel, '[data-document-pdfjs-zoom-out]')?.addEventListener('click', () => {
            if (panel === activePanel) changePdfZoom(panel, -1);
        });
        panelPart(panel, '[data-document-pdfjs-zoom-in]')?.addEventListener('click', () => {
            if (panel === activePanel) changePdfZoom(panel, 1);
        });
        panelPart(panel, '[data-document-pdfjs-fit-width]')?.addEventListener('click', () => {
            if (panel === activePanel) fitPdfToWidth(panel);
        });
        panelPart(panel, '[data-document-pdfjs-print]')?.addEventListener('click', () => {
            if (panel === activePanel) printPdfJsDocument(panel);
        });
    });

    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (!activePanel || !activeBlob) return;
            const nextMode = usesPdfJsViewer() ? 'pdfjs' : 'iframe';
            if (nextMode !== activeViewerMode) {
                applyViewerMode(activePanel, { force: true });
            } else if (nextMode === 'pdfjs' && pdfDocument && pdfFitWidth) {
                renderCurrentPdfPage(activePanel).catch(() => {
                    showPdfJsFallback(activePanel, 'Pratinjau perlu dibuka ulang setelah perubahan ukuran layar.');
                });
            }
        }, 180);
    });

    window.addEventListener('pagehide', () => {
        destroyPdfJs(activePanel);
        revokeObjectUrl();
        activeBlob = null;
    });
}
