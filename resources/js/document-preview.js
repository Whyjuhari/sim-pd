const triggerSelector = '[data-document-preview-trigger]';
const previewTriggers = Array.from(document.querySelectorAll(triggerSelector));
const previewPanels = Array.from(document.querySelectorAll('[data-document-preview]'));

if (previewTriggers.length > 0) {
    let activePanel = null;
    let activeTrigger = null;
    let activeRecord = null;
    let activeObjectUrl = null;
    let isLoading = false;
    let requestVersion = 0;
    let focusAfterLoading = null;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const panelPart = (panel, selector) => panel.querySelector(selector);

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

    const resetPanel = (panel) => {
        const frame = panelPart(panel, '[data-document-preview-frame]');
        const openLink = panelPart(panel, '[data-document-preview-open]');
        const downloadLink = panelPart(panel, '[data-document-preview-download]');
        const fallbackLink = panelPart(panel, '[data-document-preview-fallback]');

        if (frame) frame.removeAttribute('src');
        if (openLink) openLink.removeAttribute('href');
        if (downloadLink) {
            downloadLink.removeAttribute('href');
            downloadLink.removeAttribute('download');
        }
        if (fallbackLink) fallbackLink.href = '#';

        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.add('d-none');
        openLink?.classList.add('d-none');
        downloadLink?.classList.add('d-none');

        const status = panelPart(panel, '[data-document-preview-status]');
        if (status) status.textContent = '';
    };

    const closePreview = ({ restoreFocus = true } = {}) => {
        if (!activePanel) return;

        const panel = activePanel;
        const trigger = activeTrigger;

        requestVersion += 1;
        revokeObjectUrl();
        resetPanel(panel);
        panel.classList.add('d-none');
        trigger?.setAttribute('aria-expanded', 'false');
        activeRecord?.classList.remove('document-preview-owner');

        activePanel = null;
        activeTrigger = null;
        activeRecord = null;

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

    const preparePanel = (panel, trigger) => {
        const documentLabel = trigger.dataset.documentLabel ?? 'Dokumen';
        const documentNumber = panel.dataset.documentNumber ?? '';
        const label = panelPart(panel, '[data-document-preview-label]');
        const frame = panelPart(panel, '[data-document-preview-frame]');
        const fallbackLink = panelPart(panel, '[data-document-preview-fallback]');

        if (label) label.textContent = `Pratinjau ${documentLabel}`;
        if (frame) frame.title = `PDF ${documentLabel}${documentNumber ? ` ${documentNumber}` : ''}`;
        if (fallbackLink) fallbackLink.href = trigger.dataset.documentUrl ?? '#';
    };

    const showLoading = (panel, trigger) => {
        resetPanel(panel);
        preparePanel(panel, trigger);
        panelPart(panel, '[data-document-preview-loading]')?.classList.remove('d-none');

        const status = panelPart(panel, '[data-document-preview-status]');
        if (status) status.textContent = 'Menyiapkan dokumen...';
    };

    const showError = (panel, message) => {
        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.remove('d-none');

        const status = panelPart(panel, '[data-document-preview-status]');
        const errorMessage = panelPart(panel, '[data-document-preview-error-message]');
        if (status) status.textContent = 'Gagal memuat dokumen';
        if (errorMessage) errorMessage.textContent = message;
    };

    const showDocument = (panel, objectUrl, filename) => {
        const frame = panelPart(panel, '[data-document-preview-frame]');
        const openLink = panelPart(panel, '[data-document-preview-open]');
        const downloadLink = panelPart(panel, '[data-document-preview-download]');

        if (frame) frame.src = objectUrl;
        if (openLink) {
            openLink.href = objectUrl;
            openLink.classList.remove('d-none');
        }
        if (downloadLink) {
            downloadLink.href = objectUrl;
            downloadLink.download = filename;
            downloadLink.classList.remove('d-none');
        }

        panelPart(panel, '[data-document-preview-loading]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-error]')?.classList.add('d-none');
        panelPart(panel, '[data-document-preview-viewer]')?.classList.remove('d-none');

        const status = panelPart(panel, '[data-document-preview-status]');
        if (status) status.textContent = 'Dokumen siap';

        panel.scrollIntoView({
            behavior: prefersReducedMotion.matches ? 'auto' : 'smooth',
            block: 'start',
        });
    };

    const loadPreview = async (trigger, { force = false } = {}) => {
        if (isLoading) return;

        const panelId = trigger.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        const documentUrl = trigger.dataset.documentUrl;

        if (!panel || !documentUrl) return;

        if (activePanel === panel && activeTrigger === trigger && !force) {
            closePreview();
            return;
        }

        if (activePanel) closePreview({ restoreFocus: false });

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
            const response = await fetch(documentUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/pdf',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const contentType = (response.headers.get('content-type') ?? '').toLowerCase();

            if (!response.ok) throw new Error(errorMessageFor(response.status));
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
            showDocument(panel, objectUrl, trigger.dataset.documentFilename ?? 'Dokumen.pdf');
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

    previewTriggers.forEach((trigger) => {
        trigger.addEventListener('click', () => loadPreview(trigger));
    });

    previewPanels.forEach((panel) => {
        panelPart(panel, '[data-document-preview-close]')?.addEventListener('click', () => {
            if (panel === activePanel) closePreview();
        });
        panelPart(panel, '[data-document-preview-retry]')?.addEventListener('click', () => {
            if (panel === activePanel && activeTrigger) loadPreview(activeTrigger, { force: true });
        });
    });

    window.addEventListener('pagehide', revokeObjectUrl);
}
