<?php

namespace App\Support;

final class TravelReportValidation
{
    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        $documentation = config('sim_pd.documents.report_documentation');
        $maxFiles = max(1, min(2, (int) ($documentation['max_files'] ?? 2)));
        $maxKilobytes = max(1, (int) ($documentation['max_kilobytes_per_file'] ?? 5120));
        $minDimension = max(1, (int) ($documentation['min_dimension'] ?? 600));
        $maxDimension = max(
            $minDimension,
            (int) ($documentation['max_dimension'] ?? 6000)
        );

        return [
            'hasil_pelaksanaan' => [
                'required',
                'string',
                'max:20000',
            ],
            'kesimpulan' => [
                'required',
                'string',
                'max:10000',
            ],
            'foto_dokumentasi' => [
                'required',
                'array',
                'min:1',
                'max:'.$maxFiles,
            ],
            'foto_dokumentasi.*' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png',
                'max:'.$maxKilobytes,
                'dimensions:min_width='.$minDimension
                    .',min_height='.$minDimension
                    .',max_width='.$maxDimension
                    .',max_height='.$maxDimension,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'foto_dokumentasi.required' =>
                'Minimal satu foto dokumentasi wajib diunggah.',
            'foto_dokumentasi.max' =>
                'Maksimal dua foto dokumentasi dapat diunggah.',
            'foto_dokumentasi.*.image' =>
                'Dokumentasi wajib berupa gambar yang valid.',
            'foto_dokumentasi.*.mimes' =>
                'Format dokumentasi hanya boleh JPG, JPEG, atau PNG.',
            'foto_dokumentasi.*.mimetypes' =>
                'Tipe file dokumentasi tidak valid.',
            'foto_dokumentasi.*.max' =>
                'Ukuran setiap foto dokumentasi maksimal 5 MB.',
            'foto_dokumentasi.*.dimensions' =>
                'Dimensi foto dokumentasi harus antara 600 × 600 dan 6000 × 6000 piksel.',
        ];
    }
}
