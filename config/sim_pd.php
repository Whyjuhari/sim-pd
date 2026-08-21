<?php

return [
    'documents' => [
        'templates' => [
            'sppd' => resource_path('documents/SPD_157_Template.xlsx'),
            'surat_tugas' => resource_path('documents/Template_SPT_Perjadin_COLLECTIVE_V5.docx'),
            'laporan_perjadin' => resource_path('documents/Template_Laporan_Perjadin.docx'),
        ],
        'temporary_dir' => storage_path('app/private/documents/temporary'),
        'pdf_dir' => storage_path('app/private/documents/pdf'),
        'tingkat_perjadin' => env('SIMPD_TRAVEL_LEVEL', 'C'),
        'libreoffice' => [
            'binary' => env('LIBREOFFICE_BINARY', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'),
            'timeout' => (int) env('LIBREOFFICE_TIMEOUT', 60),
        ],
        'report_documentation' => [
            'max_files' => 2,
            'max_kilobytes_per_file' => 5120,
            'min_dimension' => 600,
            'max_dimension' => 6000,
            'normalized_pixels' => 1200,
            'jpeg_quality' => 85,
            'display_size_cm' => 10,
        ],
    ],
    'officials' => [
        'satker' => env('SIMPD_SATKER', 'BPVP PANGKEP'),
        'kementerian' => env('SIMPD_KEMENTERIAN', 'KEMENTERIAN KETENAGAKERJAAN RI'),
        'ppk' => [
            'name' => env('SIMPD_PPK_NAME', 'Kaharuddin, S.Pd.'),
            'nip' => env('SIMPD_PPK_NIP', '19920524 202012 1 014'),
        ],
        'bendahara' => [
            'name' => env('SIMPD_BENDAHARA_NAME', "Ni'matul Chasanah, A.Md."),
            'nip' => env('SIMPD_BENDAHARA_NIP', '198705222020122013'),
        ],
    ],
];
