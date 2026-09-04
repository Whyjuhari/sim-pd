import Choices from "choices.js";
import "choices.js/public/assets/styles/choices.min.css";

const selector = "select[data-spt-searchable]";

const commonOptions = {
    allowHTML: false,
    addChoices: false,
    duplicateItemsAllowed: false,
    itemSelectText: "Pilih",
    noChoicesText: "Tidak ada pilihan tersedia",
    noResultsText: "Data tidak ditemukan",
    searchEnabled: true,
    searchFloor: 1,
    shouldSort: false,
};

const optionsFor = (select) => {
    const placeholder = select.dataset.placeholder ?? "Cari data";

    if (select.dataset.sptSearchable === "employees") {
        return {
            ...commonOptions,
            closeDropdownOnSelect: false,
            labelId: "employeeSelectLabel",
            placeholder: true,
            placeholderValue: placeholder,
            removeItemButton: true,
            removeItemLabelText: (_value, _valueRaw, item) =>
                `Hapus ${item?.label ?? "pegawai"}`,
            searchFields: [
                "label",
                "customProperties.nip",
                "customProperties.position",
            ],
            searchPlaceholderValue: "Ketik nama, NIP, atau jabatan",
        };
    }

    return {
        ...commonOptions,
        allowHTML: false,
        labelId: "destinationSelectLabel",
        placeholder: true,
        placeholderValue: placeholder,
        searchFields: ["label", "customProperties.province"],
        searchPlaceholderValue: "Ketik kota, kabupaten, atau provinsi",
    };
};

const syncEmployeeOrder = (select, choices) => {
    const values = choices.getValue(true);
    const orderedValues = (Array.isArray(values) ? values : [values])
        .filter((value) => value !== null && value !== undefined && value !== "")
        .map(String);

    const optionsByValue = new Map(
        Array.from(select.options).map((option) => [option.value, option]),
    );

    orderedValues.forEach((value) => {
        const option = optionsByValue.get(value);

        if (!option) {
            return;
        }

        option.selected = true;
        select.append(option);
    });
};

const initializeSearchableSelects = () => {
    document.querySelectorAll(selector).forEach((select) => {
        if (!(select instanceof HTMLSelectElement) || select.dataset.choicesReady) {
            return;
        }

        select.dataset.choicesReady = "true";
        const choices = new Choices(select, optionsFor(select));

        if (select.dataset.sptSearchable !== "employees") {
            return;
        }

        select.form?.addEventListener(
            "submit",
            () => syncEmployeeOrder(select, choices),
            { capture: true },
        );
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeSearchableSelects);
} else {
    initializeSearchableSelects();
}
