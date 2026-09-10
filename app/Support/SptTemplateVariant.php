<?php

namespace App\Support;

final class SptTemplateVariant
{
    public const REGULATIONS = 'regulations';
    public const REGULATIONS_MEMO = 'regulations_memo';
    public const REGULATIONS_DIPA = 'regulations_dipa';
    public const REGULATIONS_MEMO_DIPA = 'regulations_memo_dipa';

    /** @return array<string, array{label: string, description: string, filename: string, attachment_filename: string, uses_memo: bool, uses_dipa: bool}> */
    public static function all(): array
    {
        return [
            self::REGULATIONS => [
                'label' => 'Dasar Regulasi',
                'description' => 'Dasar poin 1–3 tanpa Memo dan DIPA.',
                'filename' => 'Template_SPT_Dasar_Regulasi.docx',
                'attachment_filename' => 'Template_SPT_Dasar_Regulasi_Lampiran.docx',
                'uses_memo' => false,
                'uses_dipa' => false,
            ],
            self::REGULATIONS_MEMO => [
                'label' => 'Dasar Regulasi dan Memo',
                'description' => 'Dasar poin 1–3 ditambah Memo Internal.',
                'filename' => 'Template_SPT_Dasar_Regulasi_Memo.docx',
                'attachment_filename' => 'Template_SPT_Dasar_Regulasi_Memo_Lampiran.docx',
                'uses_memo' => true,
                'uses_dipa' => false,
            ],
            self::REGULATIONS_DIPA => [
                'label' => 'Dasar Regulasi dan DIPA',
                'description' => 'Dasar poin 1–3 ditambah DIPA tahun perjalanan.',
                'filename' => 'Template_SPT_Dasar_Regulasi_DIPA.docx',
                'attachment_filename' => 'Template_SPT_Dasar_Regulasi_DIPA_Lampiran.docx',
                'uses_memo' => false,
                'uses_dipa' => true,
            ],
            self::REGULATIONS_MEMO_DIPA => [
                'label' => 'Dasar Regulasi Memo dan DIPA',
                'description' => 'Dasar lengkap poin 1–5.',
                'filename' => 'Template_SPT_Dasar_Regulasi_Memo_DIPA.docx',
                'attachment_filename' => 'Template_SPT_Dasar_Regulasi_Memo_DIPA_Lampiran.docx',
                'uses_memo' => true,
                'uses_dipa' => true,
            ],
        ];
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /** @return array{label: string, description: string, filename: string, attachment_filename: string, uses_memo: bool, uses_dipa: bool}|null */
    public static function get(?string $key): ?array
    {
        return self::exists($key) ? self::all()[$key] : null;
    }

    public static function path(string $key, bool $withAttachment = false): string
    {
        $variant = self::get($key);

        if (! $variant) {
            throw new \InvalidArgumentException('Varian template SPT tidak dikenal.');
        }

        $filename = $withAttachment
            ? $variant['attachment_filename']
            : $variant['filename'];

        return resource_path('documents/'.$filename);
    }

    public static function pathForEmployeeCount(
        string $key,
        int $employeeCount,
        int $inlineLimit = 2,
    ): string {
        return self::path($key, $employeeCount > max(0, $inlineLimit));
    }

    public static function token(string $key): string
    {
        return 'variant:'.$key;
    }
}
