import "./bootstrap";
import * as bootstrap from "bootstrap";
import Chart from "chart.js/auto";
import Swal from "sweetalert2";
import "sweetalert2/dist/sweetalert2.min.css";
import "./document-preview";
import "./report-preview";
import "./spt-searchable-selects";
import "./spt-cost-preview";
import "./spt-template-thumbs";

window.bootstrap = bootstrap;
window.Chart = Chart;
window.Swal = Swal;

const confirmButtonClasses = {
    danger: "btn btn-danger sim-dialog-button",
    warning: "btn btn-warning sim-dialog-button",
    success: "btn btn-success sim-dialog-button",
    primary: "btn btn-primary sim-dialog-button",
};

const confirmIcons = {
    danger: "warning",
    warning: "warning",
    success: "success",
    primary: "question",
};

const dialogClasses = (confirmClass = confirmButtonClasses.primary) => ({
    container: "sim-dialog-container",
    popup: "sim-dialog-popup",
    icon: "sim-dialog-icon",
    title: "sim-dialog-title",
    htmlContainer: "sim-dialog-content",
    actions: "sim-dialog-actions",
    confirmButton: confirmClass,
    cancelButton: "btn btn-outline-secondary sim-dialog-button",
});

const dialog = Swal.mixin({
    buttonsStyling: false,
    heightAuto: false,
    returnFocus: true,
    reverseButtons: true,
    allowOutsideClick: false,
    customClass: dialogClasses(),
});

const warningDialog = (message, title = "Periksa kembali") => {
    const origin =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

    origin?.blur();

    return dialog
        .fire({
            icon: "warning",
            title,
            text: message,
            showCancelButton: false,
            showDenyButton: false,
            focusConfirm: true,
            confirmButtonText:
                '<i class="bi bi-check-lg" aria-hidden="true"></i> Mengerti',
            customClass: dialogClasses(confirmButtonClasses.warning),
            didOpen: (popup) => {
                window.setTimeout(
                    () => popup.querySelector(".swal2-confirm")?.focus(),
                    50,
                );
            },
        })
        .then((result) => {
            origin?.focus({ preventScroll: true });

            return result;
        });
};

window.SimPdDialog = Object.freeze({
    warning: warningDialog,
});

const confirmedForms = new WeakSet();
const pendingForms = new WeakSet();

document.addEventListener("submit", async (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (confirmedForms.has(form)) {
        confirmedForms.delete(form);

        return;
    }

    const submitter = event.submitter;
    const confirmationSource = submitter?.matches?.("[data-sim-confirm]")
        ? submitter
        : form.matches("[data-sim-confirm]")
          ? form
          : null;

    if (!confirmationSource) {
        return;
    }

    event.preventDefault();

    if (pendingForms.has(form)) {
        return;
    }

    pendingForms.add(form);

    const tone = confirmationSource.dataset.simConfirmTone ?? "primary";
    const result = await dialog.fire({
        icon: confirmIcons[tone] ?? confirmIcons.primary,
        title:
            confirmationSource.dataset.simConfirmTitle ??
            "Lanjutkan tindakan ini?",
        text: confirmationSource.dataset.simConfirmText ?? "",
        confirmButtonText:
            confirmationSource.dataset.simConfirmButton ?? "Ya, lanjutkan",
        cancelButtonText: "Batal",
        showCancelButton: true,
        focusCancel: tone === "danger",
        customClass: dialogClasses(
            confirmButtonClasses[tone] ?? confirmButtonClasses.primary,
        ),
    });

    pendingForms.delete(form);

    if (!result.isConfirmed) {
        return;
    }

    confirmedForms.add(form);

    if (
        submitter instanceof HTMLButtonElement ||
        submitter instanceof HTMLInputElement
    ) {
        form.requestSubmit(submitter);
    } else {
        form.requestSubmit();
    }
});

document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll(".app-body .table-responsive > table.table")
        .forEach((table) => {
            table.classList.add("responsive-records");
            const labels = Array.from(table.querySelectorAll("thead th")).map(
                (heading) => heading.textContent.replace(/\s+/g, " ").trim(),
            );

            table.querySelectorAll("tbody tr").forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (cell.hasAttribute("colspan")) {
                        cell.classList.add("responsive-records-empty");
                        return;
                    }

                    if (!cell.dataset.label && labels[index]) {
                        cell.dataset.label = labels[index];
                    }
                });
            });
        });

    const openPrimaryRecord = (record) => {
        const documentTriggerId = record.dataset.primaryDocumentTrigger;

        if (documentTriggerId) {
            document.getElementById(documentTriggerId)?.click();
            return;
        }

        const url = record.dataset.primaryUrl;
        if (url) window.location.assign(url);
    };

    document
        .querySelectorAll("[data-primary-url], [data-primary-document-trigger]")
        .forEach((record) => {
            record.addEventListener("click", (event) => {
                if (
                    event.target.closest(
                        "a, button, input, select, textarea, label, form",
                    )
                )
                    return;
                openPrimaryRecord(record);
            });
            record.addEventListener("keydown", (event) => {
                if (
                    (event.key === "Enter" || event.key === " ") &&
                    event.target === record
                ) {
                    event.preventDefault();
                    openPrimaryRecord(record);
                }
            });
        });

    const successMessage = document.body.dataset.flashSuccess;

    if (successMessage) {
        const successToast = Swal.mixin({
            toast: true,
            position: "top-end",
            iconColor: "var(--sim-success)",
            showConfirmButton: false,
            showCloseButton: true,
            timer: 3800,
            timerProgressBar: true,
            customClass: {
                container: "sim-toast-container",
                popup: "sim-toast-popup",
                title: "sim-toast-title",
                htmlContainer: "sim-toast-content",
                timerProgressBar: "sim-toast-progress",
            },
            didOpen: (toast) => {
                toast.addEventListener("mouseenter", Swal.stopTimer);
                toast.addEventListener("mouseleave", Swal.resumeTimer);
                toast.addEventListener("touchstart", Swal.stopTimer, {
                    passive: true,
                });
                toast.addEventListener("touchend", Swal.resumeTimer, {
                    passive: true,
                });
            },
        });

        successToast.fire({
            icon: "success",
            title: "Berhasil",
            text: successMessage,
        });
    }
});
