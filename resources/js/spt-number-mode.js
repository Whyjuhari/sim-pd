const initializeSptNumberMode = () => {
    document.querySelectorAll("[data-spt-number-mode]").forEach((field) => {
        const parameterPanel = field.querySelector("[data-spt-number-parameter]");
        const manualPanel = field.querySelector("[data-spt-number-manual]");
        const manualInput = manualPanel?.querySelector("input[name='no_spt']");

        if (!parameterPanel || !manualPanel || !(manualInput instanceof HTMLInputElement)) {
            return;
        }

        const update = () => {
            const selected = field.querySelector("input[name='spt_number_mode']:checked");
            const manual = selected?.value === "manual";

            parameterPanel.hidden = manual;
            manualPanel.hidden = !manual;
            manualInput.required = manual;
            manualInput.disabled = !manual;
        };

        field.addEventListener("change", (event) => {
            if (event.target instanceof HTMLInputElement && event.target.name === "spt_number_mode") {
                update();
            }
        });
        update();
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeSptNumberMode, { once: true });
} else {
    initializeSptNumberMode();
}
