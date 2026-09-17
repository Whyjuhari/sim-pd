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
            closeDropdownOnSelect: true,
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

const arrangeEmployeeDropdownSearch = (select, choices) => {
    if (select.dataset.sptLayout !== "dropdown-search") {
        return;
    }

    const container = select.closest(".choices");
    const inner = container?.querySelector(".choices__inner");
    const dropdown = container?.querySelector(".choices__list--dropdown");
    const dropdownResults = dropdown?.querySelector(".choices__list[role='listbox']");
    const searchInput = container?.querySelector(".choices__input--cloned");

    if (!container || !inner || !dropdown || !dropdownResults || !searchInput) {
        return;
    }

    container.classList.add("choices--dropdown-search");
    searchInput.classList.add("choices__input--dropdown-search");
    searchInput.placeholder = "Ketik nama, NIP, atau jabatan";
    searchInput.setAttribute("aria-label", "Cari pegawai berdasarkan nama, NIP, atau jabatan");
    dropdown.insertBefore(searchInput, dropdownResults);

    const placeholder = document.createElement("span");
    placeholder.className = "choices__placeholder choices__closed-placeholder";
    placeholder.textContent = select.dataset.placeholder ?? "Cari dan pilih pegawai";
    placeholder.setAttribute("aria-hidden", "true");
    inner.append(placeholder);

    const updatePlaceholder = () => {
        const selectedValues = choices.getValue(true);
        const hasSelection = Array.isArray(selectedValues)
            ? selectedValues.length > 0
            : selectedValues !== null && selectedValues !== undefined && selectedValues !== "";

        placeholder.classList.toggle("d-none", hasSelection);
    };

    select.addEventListener("addItem", updatePlaceholder);
    select.addEventListener("removeItem", updatePlaceholder);
    select.addEventListener("showDropdown", () => {
        window.requestAnimationFrame(() => searchInput.focus({ preventScroll: true }));
    });
    updatePlaceholder();
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

        arrangeEmployeeDropdownSearch(select, choices);

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
