const pageSelector = "[data-live-filter-page]";
const formSelector = "form[data-live-filter]";
const textInputTypes = new Set(["search", "text"]);

let debounceTimer = null;
let activeRequest = null;
let requestSequence = 0;

document.documentElement.classList.add("live-filters-enabled");

const pageRoot = () => document.querySelector(pageSelector);

const findLiveForm = (id, root = document) =>
    Array.from(root.querySelectorAll(formSelector)).find(
        (form) => form.dataset.liveFilter === id,
    );

const formUrl = (form) => {
    const url = new URL(form.getAttribute("action") || window.location.pathname, window.location.origin);
    const params = new URLSearchParams();

    new FormData(form).forEach((value, key) => {
        if (typeof value !== "string") return;

        const normalized = value.trim();
        if (normalized !== "") params.append(key, normalized);
    });

    url.search = params.toString();

    return url;
};

const focusState = (form, field) => {
    if (!(field instanceof HTMLElement)) return null;

    return {
        formId: form.dataset.liveFilter,
        fieldName: field.getAttribute("name"),
        selectionStart:
            field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement
                ? field.selectionStart
                : null,
        selectionEnd:
            field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement
                ? field.selectionEnd
                : null,
    };
};

const restoreFocus = (state) => {
    if (!state?.formId || !state.fieldName) return;

    const form = findLiveForm(state.formId);
    const field = form
        ? Array.from(form.elements).find(
              (element) => element.getAttribute?.("name") === state.fieldName,
          )
        : null;

    if (!(field instanceof HTMLElement)) return;

    const collapsedParent = field.closest(".collapse");
    if (collapsedParent && !collapsedParent.classList.contains("show")) {
        collapsedParent.classList.add("show");
        const collapseId = collapsedParent.id;
        if (collapseId) {
            document
                .querySelectorAll(`[aria-controls="${collapseId}"]`)
                .forEach((trigger) => trigger.setAttribute("aria-expanded", "true"));
        }
    }

    field.focus({ preventScroll: true });
    if (
        (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) &&
        state.selectionStart !== null
    ) {
        field.setSelectionRange(state.selectionStart, state.selectionEnd);
    }
};

const showRequestError = () => {
    const message = "Data belum dapat diperbarui. Periksa koneksi lalu coba kembali.";

    if (window.SimPdDialog?.warning) {
        window.SimPdDialog.warning(message, "Pencarian gagal");
        return;
    }

    window.alert(message);
};

const loadResults = async (
    url,
    { historyMode = "replace", focus = null } = {},
) => {
    const root = pageRoot();
    if (!root) return;

    activeRequest?.abort();
    const controller = new AbortController();
    activeRequest = controller;
    const sequence = ++requestSequence;

    root.setAttribute("aria-busy", "true");
    document.documentElement.classList.add("live-filter-loading");

    try {
        const response = await fetch(url, {
            credentials: "same-origin",
            headers: {
                Accept: "text/html",
                "X-Requested-With": "XMLHttpRequest",
            },
            signal: controller.signal,
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const html = await response.text();
        const nextDocument = new DOMParser().parseFromString(html, "text/html");
        const nextRoot = nextDocument.querySelector(pageSelector);

        if (!nextRoot) {
            window.location.assign(response.url || url.toString());
            return;
        }

        if (sequence !== requestSequence) return;

        document.dispatchEvent(
            new CustomEvent("sim:content-will-replace", { detail: { root } }),
        );
        root.innerHTML = nextRoot.innerHTML;
        document.title = nextDocument.title || document.title;

        const nextUrl = response.url || url.toString();
        if (historyMode === "push") {
            window.history.pushState({ liveFilter: true }, "", nextUrl);
        } else if (historyMode === "replace") {
            window.history.replaceState({ liveFilter: true }, "", nextUrl);
        }

        document.dispatchEvent(
            new CustomEvent("sim:content-updated", { detail: { root } }),
        );
        window.requestAnimationFrame(() => restoreFocus(focus));
    } catch (error) {
        if (error?.name !== "AbortError") showRequestError();
    } finally {
        if (sequence === requestSequence) {
            root.removeAttribute("aria-busy");
            document.documentElement.classList.remove("live-filter-loading");
            activeRequest = null;
        }
    }
};

const submitLiveForm = (form, field = null, immediate = false) => {
    window.clearTimeout(debounceTimer);
    if (!form.checkValidity()) return;

    const run = () =>
        loadResults(formUrl(form), {
            historyMode: "replace",
            focus: focusState(form, field),
        });

    if (immediate) {
        run();
    } else {
        debounceTimer = window.setTimeout(run, 400);
    }
};

document.addEventListener("input", (event) => {
    const field = event.target;
    const form = field?.closest?.(formSelector);

    if (!(form instanceof HTMLFormElement) || !(field instanceof HTMLInputElement)) return;
    if (!textInputTypes.has(field.type)) return;

    submitLiveForm(form, field);
});

document.addEventListener("change", (event) => {
    const field = event.target;
    const form = field?.closest?.(formSelector);

    if (!(form instanceof HTMLFormElement) || !(field instanceof HTMLElement)) return;
    if (field instanceof HTMLInputElement && textInputTypes.has(field.type)) return;

    submitLiveForm(form, field, true);
});

document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches(formSelector)) return;

    event.preventDefault();
    submitLiveForm(form, event.submitter, true);
});

document.addEventListener("click", (event) => {
    const filterLink = event.target.closest("a[data-live-filter-link]");
    if (filterLink) {
        event.preventDefault();
        loadResults(new URL(filterLink.href), { historyMode: "push" });
        return;
    }

    const reset = event.target.closest("a[data-live-filter-reset]");
    if (reset) {
        event.preventDefault();
        loadResults(new URL(reset.href), { historyMode: "replace" });
        return;
    }

    const pagination = event.target.closest(`${pageSelector} .pagination a[href]`);
    if (!pagination || !pageRoot()?.querySelector(formSelector)) return;

    event.preventDefault();
    loadResults(new URL(pagination.href), { historyMode: "push" });
});

window.addEventListener("popstate", () => {
    loadResults(new URL(window.location.href), { historyMode: "none" });
});
