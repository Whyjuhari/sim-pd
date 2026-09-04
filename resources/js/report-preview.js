const initializeReportPreview = (form) => {
    const previewTrigger = form.querySelector('[data-report-preview-trigger]');
    const panelId = previewTrigger?.getAttribute('aria-controls');
    const panel = panelId ? document.getElementById(panelId) : null;

    if (!(previewTrigger instanceof HTMLButtonElement) || !panel) {
        return;
    }

    const formSection = form.closest('[data-report-form-section]');
    const reviewButton = panel.querySelector('[data-report-preview-review]');
    const confirmButton = panel.querySelector('[data-report-preview-confirm]');
    const closeButton = panel.querySelector('[data-document-preview-close]');
    const firstEditableField = form.querySelector('[name="hasil_pelaksanaan"]');
    const workflow = document.querySelector('[data-report-workflow]');
    let previewReady = false;
    let allowSubmit = false;
    let submitting = false;

    const setWorkflowStep = (activeStep) => {
        workflow?.querySelectorAll('[data-workflow-step]').forEach((step) => {
            const stepNumber = Number.parseInt(step.dataset.workflowStep ?? '', 10);
            step.classList.toggle('done', stepNumber < activeStep);
            step.classList.toggle('active', stepNumber === activeStep);
        });
        workflow?.setAttribute('aria-label', `Tahap ${activeStep} dari 6`);
    };

    const scrollToPageTop = () => {
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    };

    const showPreviewView = () => {
        formSection?.classList.add('d-none');
        formSection?.setAttribute('aria-hidden', 'true');
        panel.setAttribute('aria-hidden', 'false');
        previewTrigger.setAttribute('aria-expanded', 'true');
        setWorkflowStep(3);
        scrollToPageTop();
    };

    const showFormView = () => {
        formSection?.classList.remove('d-none');
        formSection?.setAttribute('aria-hidden', 'false');
        panel.setAttribute('aria-hidden', 'true');
        previewTrigger.setAttribute('aria-expanded', 'false');
        if (!submitting) setWorkflowStep(2);
        scrollToPageTop();
    };

    const resetConfirmButton = () => {
        previewReady = false;
        if (confirmButton instanceof HTMLButtonElement) confirmButton.disabled = true;
    };

    const closePreview = () => {
        if (!panel.classList.contains('d-none')) {
            closeButton?.click();
        } else {
            resetConfirmButton();
            showFormView();
        }
    };

    form.addEventListener('submit', (event) => {
        if (allowSubmit) {
            if (submitting) {
                event.preventDefault();
                return;
            }

            submitting = true;
            form.setAttribute('aria-busy', 'true');
            previewTrigger.disabled = true;
            if (confirmButton instanceof HTMLButtonElement) confirmButton.disabled = true;
            return;
        }

        event.preventDefault();

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        if (previewReady) {
            confirmButton?.focus();
            return;
        }

        previewTrigger.click();
    });

    form.addEventListener('input', resetConfirmButton);
    form.addEventListener('change', () => {
        if (previewReady || !panel.classList.contains('d-none')) closePreview();
    });

    panel.addEventListener('document-preview:loading', () => {
        resetConfirmButton();
        showPreviewView();
    });
    panel.addEventListener('document-preview:error', () => {
        resetConfirmButton();
        showPreviewView();
    });
    panel.addEventListener('document-preview:closed', () => {
        resetConfirmButton();
        showFormView();
    });
    panel.addEventListener('document-preview:ready', () => {
        previewReady = true;
        if (confirmButton instanceof HTMLButtonElement) confirmButton.disabled = false;
        showPreviewView();
    });

    reviewButton?.addEventListener('click', () => {
        closePreview();
        window.setTimeout(() => firstEditableField?.focus({ preventScroll: true }), 0);
    });

    confirmButton?.addEventListener('click', () => {
        if (!previewReady || submitting) return;

        allowSubmit = true;
        form.requestSubmit();
    });

    panel.setAttribute('aria-hidden', 'true');
    formSection?.setAttribute('aria-hidden', 'false');
    setWorkflowStep(2);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-report-preview-form]').forEach(initializeReportPreview);
});
