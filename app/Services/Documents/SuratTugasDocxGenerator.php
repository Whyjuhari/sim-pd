<?php

namespace App\Services\Documents;

use Carbon\Carbon;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

class SuratTugasDocxGenerator
{
    public function __construct(
        private readonly string $templatePath,
        private readonly string $outputDirectory,
    ) {}

    public function generate(array $data): string
    {
        if (! is_file($this->templatePath)) {
            throw new RuntimeException('Template SPT tidak ditemukan: ' . $this->templatePath);
        }

        if (! is_dir($this->outputDirectory) && ! mkdir($this->outputDirectory, 0775, true) && ! is_dir($this->outputDirectory)) {
            throw new RuntimeException('Folder sementara dokumen tidak dapat dibuat.');
        }

        $template = new TemplateProcessor($this->templatePath);
        $employees = $data['pegawai_list'] ?? [];

        if ($employees === []) {
            throw new RuntimeException('Daftar pegawai Surat Tugas tidak tersedia.');
        }

        $template->cloneRowAndSetValues('nomor_pegawai', array_map(
            fn(array $employee, int $index): array => [
                'kepada_pegawai' => $index === 0 ? 'Kepada' : '',
                'pemisah_kepada' => $index === 0 ? ':' : '',
                'nomor_pegawai' => ($index + 1) . '.',
                'no_urut' => ($index + 1) . '.',
                'nama_pegawai' => $this->employeeValue($employee['nama_lengkap'] ?? null),
                'nip' => $this->employeeValue($employee['nip'] ?? null),
                'pangkat_golongan' => $this->employeeValue($employee['pangkat_golongan'] ?? null),
                'jabatan' => $this->employeeValue($employee['jabatan'] ?? null),
            ],
            $employees,
            array_keys($employees),
        ));


        $template->setValues([
            'nomor_surat' => $data['no_spt'] ?? '',
            'menimbang' => $data['menimbang'] ?? '',
            'nomor_memo' => $data['no_memo'] ?? '-',
            'perihal_memo' => $data['perihal_memo'] ?? '-',
            'tanggal_memo' => $this->formatDate($data['tgl_memo'] ?? null),
            'akun' => $data['akun_anggaran'] ?? '-',
            'tanggal_pelaksanaan' => $this->formatDate($data['tgl_berangkat'] ?? null),
            'tanggal_surat' => Carbon::now()->locale('id')->translatedFormat('d F Y'),
        ]);
        $template->setValue('untuk_kegiatan', $this->formatUntukKegiatan($data));
        $filename = $this->sanitize('SPT_' . ($data['no_spt'] ?? 'tanpa-nomor'))
            . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.docx';
        $path = rtrim($this->outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $template->saveAs($path);

        return $path;
    }

    private function employeeValue(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : '-';
    }

    private function formatDate(?string $date, bool $withYear = true): string
    {
        if (!$date) {
            return '-';
        }

        $format = $withYear ? 'd F Y' : 'd F';

        return Carbon::parse($date)->locale('id')->translatedFormat($format);
    }
    private function sanitize(string $filename): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/u', '_', $filename) ?: 'SPT', '._-');
    }
    private function formatUntukKegiatan(array $data): string
    {
        $maksud = $data['maksud_perjalanan'] ?? '';
        $mulaiRaw = $data['tgl_berangkat'] ?? null;
        $selesaiRaw = $data['tgl_kembali'] ?? null;
        $lama = (int) ($data['lama_hari'] ?? 0);

        if (! $mulaiRaw) {
            return $maksud;
        }

        $mulai = Carbon::parse($mulaiRaw);
        $selesai = $selesaiRaw ? Carbon::parse($selesaiRaw) : null;

        if (! $selesai || $mulai->isSameDay($selesai)) {
            return "{$maksud} pada tanggal {$this->formatDate($mulaiRaw)}, selama {$lama} hari";
        }

        $tglSelesai = $this->formatDate($selesaiRaw);

        $tglMulai = match (true) {
            $mulai->isSameMonth($selesai) && $mulai->isSameYear($selesai) => $mulai->format('d'),
            $mulai->isSameYear($selesai) => $this->formatDate($mulaiRaw, false),
            default => $this->formatDate($mulaiRaw),
        };

        return "{$maksud} dari tanggal {$tglMulai} - {$tglSelesai}, selama {$lama} hari";
    }
}