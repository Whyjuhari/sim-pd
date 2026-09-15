<?php

namespace App\Services\Documents;

class SptOfficialNumberParser
{
    public function hasUnresolvedDraftPlaceholders(string $text): bool
    {
        return preg_match(
            '/\$\s*\{\s*(?:nomor_naskah|ttd_pengirim|ttd)\s*\}/iu',
            $text,
        ) === 1;
    }

    public function parse(string $text): ?string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $lines = preg_split('/\n/u', $text) ?: [];
        $lines = array_values(array_filter(array_map(
            fn(string $line): string => trim((string) preg_replace('/[\t ]+/u', ' ', $line)),
            $lines,
        ), fn(string $line): bool => $line !== ''));

        if ($lines === []) {
            return null;
        }

        $headingIndexes = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/\bSURAT\s+(?:PERINTAH\s+)?TUGAS\b/iu', $line) === 1) {
                $headingIndexes[] = $index;
            }
        }

        $ranges = $headingIndexes === []
            ? [[0, min(count($lines) - 1, 35)]]
            : array_map(
                fn(int $index): array => [$index, min(count($lines) - 1, $index + 15)],
                $headingIndexes,
            );

        foreach ($ranges as [$start, $end]) {
            for ($index = $start; $index <= $end; $index++) {
                $line = $lines[$index];
                if (preg_match(
                    '/^(?:(?:DASAR|MENIMBANG)\s+|SURAT\s+(?:PERINTAH\s+)?TUGAS\s+)?(?:NOMOR(?:\s+(?:NASKAH|SURAT(?:\s+TUGAS)?))?|NO\.?\s*(?:NASKAH|SURAT)?)\s*:?[\t ]*(.*)$/iu',
                    $line,
                    $matches,
                ) !== 1) {
                    continue;
                }

                $sameLine = $this->validCandidate((string) ($matches[1] ?? ''));
                if ($sameLine !== null) {
                    return $sameLine;
                }

                if (($index + 1) <= $end) {
                    $nextLine = $this->validCandidate($lines[$index + 1]);
                    if ($nextLine !== null) {
                        return $nextLine;
                    }
                }
            }
        }

        return null;
    }

    private function validCandidate(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B:;");
        $value = (string) preg_replace('/\s*([.\/_-])\s*/u', '$1', $value);
        $candidates = array_values(array_unique([
            $value,
            ...(preg_split('/\s+/u', $value) ?: []),
        ]));

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate, " \t\n\r\0\x0B:;,");
            if (
                $candidate === ''
                || mb_strlen($candidate) > 50
                || str_contains($candidate, '${nomor_naskah}')
                || (strlen($candidate) < 2)
                || preg_match('/\d/u', $candidate) !== 1
                || preg_match('/\A[A-Z0-9][A-Z0-9.\/_-]*[A-Z0-9]\z/iu', $candidate) !== 1
                || (! str_contains($candidate, '/') && preg_match('/\A\d+\z/', $candidate) !== 1)
            ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
