// Officer detail only. Document rendering remains owned by the shared viewer.
const processPanel = document.querySelector('[data-officer-spt-process]');

if (processPanel) {
    const download = processPanel.querySelector('[data-spt-concept-download]');
    const sendForm = processPanel.querySelector('[data-spt-send-form]');
    const controls = processPanel.querySelector('[data-spt-send-controls]');
    const status = processPanel.querySelector('[data-spt-concept-status]');
    const setStatus = (message) => {
        if (status) status.textContent = message;
    };
    let pending = null;
    const downloadUrls = new Set();

    let submitted = false;
    sendForm?.addEventListener('submit', (event) => {
        if (submitted) {
            event.preventDefault();
            return;
        }
        if (!sendForm.checkValidity()) return;
        submitted = true;
        sendForm.querySelector('button[type="submit"], button:not([type])')?.setAttribute('disabled', '');
    });

    download?.addEventListener('click', async (event) => {
        // Preserve native open/save actions and the no-JavaScript download link.
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (pending) return;

        const wasDisabled = controls?.disabled ?? false;
        pending = new AbortController();
        if (controls) controls.disabled = true;
        download.setAttribute('aria-disabled', 'true');
        download.setAttribute('aria-busy', 'true');
        try {
            setStatus('Menyiapkan file Word. Tunggu sampai unduhan dimulai…');
            const response = await fetch(download.href, {
                credentials: 'same-origin',
                headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document, application/json' },
                signal: pending.signal,
            });
            const mime = response.headers.get('content-type') || '';
            if (!response.ok || !mime.includes('application/vnd.openxmlformats-officedocument.wordprocessingml.document')) {
                throw new Error([401, 419].includes(response.status) || response.redirected
                    ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.'
                    : 'File Word belum dapat diunduh. Muat ulang halaman untuk memeriksa status SPT, lalu coba lagi.');
            }
            const blob = await response.blob();
            if (!blob.size) throw new Error('File Word kosong. Silakan coba lagi.');
            const version = response.headers.get('x-spt-concept-version');
            const url = URL.createObjectURL(blob);
            downloadUrls.add(url);
            const link = document.createElement('a');
            link.href = url;
            link.download = download.dataset.downloadName.replace(/\.docx$/, /^\d+$/.test(version || '') ? `_V${version}.docx` : '.docx');
            document.body.append(link);
            link.click();
            link.remove();
            window.setTimeout(() => { URL.revokeObjectURL(url); downloadUrls.delete(url); }, 30000);
            if (controls) controls.disabled = false;
            sendForm?.classList.remove('d-none');
            download.classList.replace('btn-primary', 'btn-outline-primary');
            setStatus(`Unduhan Word${/^\d+$/.test(version || '') ? ` versi ${version}` : ''} dimulai. Setelah diunggah ke SRIKANDI, catat pengiriman di bawah.`);
        } catch (error) {
            if (controls) controls.disabled = wasDisabled;
            if (error.name !== 'AbortError') {
                setStatus(error instanceof TypeError
                    ? 'Koneksi terputus. Periksa koneksi lalu coba unduh kembali.' : error.message);
            }
        } finally {
            pending = null;
            download.removeAttribute('aria-disabled');
            download.removeAttribute('aria-busy');
        }
    });

    // Upload redirects back with a one-time flag; fetching stays in the shared viewer.
    processPanel.querySelector('[data-spt-open-preview]')?.click();

    window.addEventListener('pagehide', () => {
        pending?.abort();
        downloadUrls.forEach((url) => URL.revokeObjectURL(url));
        downloadUrls.clear();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
}
