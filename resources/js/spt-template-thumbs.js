import pdfWorkerUrl from "pdfjs-dist/legacy/build/pdf.worker.min.mjs?url";

let pdfJsPromise = null;
let thumbnailQueue = Promise.resolve();

const getPdfJs = () => {
    if (!pdfJsPromise) {
        pdfJsPromise = import("pdfjs-dist/legacy/build/pdf.mjs").then(
            (pdfJs) => {
                pdfJs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
                return pdfJs;
            },
        );
    }
    return pdfJsPromise;
};

const renderThumb = async (canvas, url) => {
    const pdfJs = await getPdfJs();
    const loadingTask = pdfJs.getDocument({
        url,
        rangeChunkSize: 65536,
        disableAutoFetch: false,
        disableStream: false,
    });
    let doc = null;

    try {
        doc = await loadingTask.promise;
        const page = await doc.getPage(1);
        const desiredWidth = canvas.clientWidth || 220;
        const viewport = page.getViewport({ scale: 1 });
        const scale = Math.min(desiredWidth / viewport.width, 2);
        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
        const scaled = page.getViewport({ scale: scale * outputScale });

        canvas.width = Math.max(1, Math.floor(scaled.width));
        canvas.height = Math.max(1, Math.floor(scaled.height));

        const ctx = canvas.getContext("2d");
        if (!ctx) return;

        await page.render({ canvasContext: ctx, viewport: scaled }).promise;
    } catch {
        canvas.closest(".spt-thumb-wrap")?.classList.add("spt-thumb-failed");
    } finally {
        if (doc) {
            await doc.destroy();
        } else {
            await loadingTask.destroy();
        }
    }
};

const queueThumb = (canvas) => {
    thumbnailQueue = thumbnailQueue
        .catch(() => undefined)
        .then(() => renderThumb(canvas, canvas.dataset.sptTemplateThumb))
        .catch(() => {
            canvas
                .closest(".spt-thumb-wrap")
                ?.classList.add("spt-thumb-failed");
        });
};

const init = () => {
    const items = document.querySelectorAll("[data-spt-template-thumb]");
    if (!items.length) return;

    if ("IntersectionObserver" in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    observer.unobserve(entry.target);
                    queueThumb(entry.target);
                });
            },
            { rootMargin: "200px 0px" },
        );
        items.forEach((canvas) => observer.observe(canvas));
    } else {
        items.forEach(queueThumb);
    }
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
