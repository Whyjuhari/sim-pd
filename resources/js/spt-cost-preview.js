const rupiah = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const previewFields = [
    'kota_tujuan',
    'tempat_berangkat',
    'tgl_berangkat',
    'tgl_kembali',
    'angkutan',
    'daily_allowance_category',
];

const createElement = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
};

const renderError = (panel, message) => {
    panel.replaceChildren(createElement('div', 'alert alert-danger mb-0', message));
    panel.hidden = false;
};

const renderPreview = (panel, result) => {
    const card = createElement('div', 'border rounded-4 overflow-hidden');
    const header = createElement('div', 'spt-preview-header');
    const heading = createElement('div');
    heading.append(
        createElement('div', 'small text-muted', `${result.days} hari perjalanan`),
        createElement('strong', 'd-block', 'Rincian Estimasi')
    );
    header.append(heading, createElement('strong', 'text-identity', rupiah.format(result.total)));
    card.append(header);

    const list = createElement('div', 'spt-preview-list');
    result.components.forEach((component) => {
        const row = createElement('div', 'spt-preview-row');
        const description = createElement('div', 'min-w-0');
        description.append(
            createElement('div', 'fw-semibold', component.label),
            createElement('div', 'small text-muted', `${rupiah.format(component.rate)} × ${component.quantity} ${component.unit}`)
        );
        const amount = createElement('div', 'text-end');
        amount.append(
            createElement('div', 'fw-semibold text-nowrap', rupiah.format(component.total)),
            createElement('span', `badge rounded-pill text-bg-${component.tone}`, component.source)
        );
        row.append(description, amount);
        list.append(row);
    });
    card.append(list);

    if (result.has_fallback) {
        card.append(createElement('div', 'spt-preview-note', 'Ada komponen legacy atau tarif belum tersedia.'));
    }

    panel.replaceChildren(card);
    panel.hidden = false;
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-spt-cost-preview]').forEach((preview) => {
        const form = preview.closest('form');
        const button = preview.querySelector('[data-spt-preview-trigger]');
        const panel = preview.querySelector('[data-spt-preview-panel]');
        if (!(form instanceof HTMLFormElement) || !(button instanceof HTMLButtonElement) || !panel) return;

        const resetPreview = () => {
            panel.hidden = true;
            panel.replaceChildren();
        };
        previewFields.forEach((name) => form.elements.namedItem(name)?.addEventListener('change', resetPreview));

        button.addEventListener('click', async () => {
            const payload = new FormData();
            for (const name of previewFields) {
                const input = form.elements.namedItem(name);
                const value = input instanceof RadioNodeList ? input.value : input?.value;
                if (['kota_tujuan', 'tempat_berangkat', 'tgl_berangkat', 'tgl_kembali', 'angkutan'].includes(name) && !value) {
                    input?.focus();
                    window.SimPdDialog?.warning('Lengkapi tujuan, tanggal, dan angkutan terlebih dahulu.');
                    return;
                }
                if (value) payload.append(name, value);
            }
            if (preview.dataset.sptGroupId) payload.append('spt_group_id', preview.dataset.sptGroupId);

            const token = form.querySelector('input[name="_token"]')?.value;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            panel.hidden = false;
            panel.replaceChildren(createElement('div', 'text-muted py-3', 'Menghitung...'));

            try {
                const response = await fetch(preview.dataset.previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token ?? '' },
                    body: payload,
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const validationMessage = Object.values(data.errors ?? {}).flat()[0];
                    throw new Error(validationMessage || data.message || 'Perhitungan belum dapat dibuat.');
                }
                renderPreview(panel, data);
            } catch (error) {
                renderError(panel, error instanceof Error ? error.message : 'Perhitungan belum dapat dibuat.');
            } finally {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        });
    });
});
