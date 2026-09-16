<?php

namespace App\Services\Documents;

class SptOfficialNumberParser
{
    public function hasUnresolvedNumberPlaceholders(string $text): bool
    {
        return preg_match(
            '/\$\s*\{\s*(?:nomor_naskah)\s*\}/iu',
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
            ? [[0, min(count($lines) - 1, 35), false]]
            : array_map(
                fn(int $index): array => [$index, min(count($lines) - 1, $index + 15), true],
                $headingIndexes,
            );

        $headerCandidates = [];

        foreach ($ranges as [$start, $end, $hasHeading]) {
            for ($index = $start; $index <= $end; $index++) {
                $line = $lines[$index];
                $isNumberLabel = preg_match(
                    '/^(?:(?:DASAR|MENIMBANG)\s+|SURAT\s+(?:PERINTAH\s+)?TUGAS\s+)?(?:NOMOR(?:\s+(?:NASKAH|SURAT(?:\s+TUGAS)?))?|NO\.?\s*(?:NASKAH|SURAT)?)\s*:?[\t ]*(.*)$/iu',
                    $line,
                    $matches,
                ) === 1;

                if ($isNumberLabel) {
                    $labelValue = (string) ($matches[1] ?? '');
                    $sameLine = $this->labeledCandidate($labelValue);
                    if ($sameLine !== null) {
                        $headerCandidates[] = [
                            'value' => $sameLine,
                            'score' => 100,
                        ];
                    } elseif (
                        ! $this->hasUnresolvedNumberPlaceholders($labelValue)
                        && ($index + 1) <= $end
                    ) {
                        $nextLine = $this->standaloneCandidate($lines[$index + 1]);
                        if ($nextLine !== null) {
                            $headerCandidates[] = [
                                'value' => $nextLine,
                                'score' => 90,
                            ];
                        }
                    }
                }

                // Ekstraksi layout dapat menghasilkan "Menimbang SURAT TUGAS"
                // lalu "Dasar NOMOR ...". Baris batas tetap diproses sebelum
                // pencarian bagian header dihentikan.
                if (
                    $hasHeading
                    && $index > $start
                    && $this->isBodyBoundary($line)
                ) {
                    break;
                }
            }
        }

        if ($headerCandidates !== []) {
            return $this->resolveCandidates($headerCandidates);
        }

        return $this->resolveCandidates($this->detachedOfficialCandidates($lines));
    }

    /**
     * SRIKANDI dapat menambahkan nomor sebagai lapisan terpisah. Pada hasil
     * ekstraksi raw, nomor tersebut biasanya berada dekat parameter tanda
     * tangan atau keterangan penandatanganan elektronik, bukan dekat judul.
     *
     * @param  list<string>  $lines
     * @return list<array{value: string, score: int}>
     */
    private function detachedOfficialCandidates(array $lines): array
    {
        $candidates = [];

        foreach ($lines as $index => $line) {
            $candidate = $this->standaloneCandidate($line);
            if (
                $candidate === null
                || substr_count($candidate, '/') < 3
                || preg_match('/\/[IVXLCDM]+\/(?:19|20)\d{2}\z/iu', $candidate) !== 1
                || $this->isMemoContext($lines, $index)
            ) {
                continue;
            }

            $context = implode(' ', array_slice(
                $lines,
                max(0, $index - 4),
                9,
            ));
            if (preg_match('/(?:\$\s*\{\s*ttd|ditandatangani\s+secara\s+elektronik)/iu', $context) === 1) {
                $candidates[] = [
                    'value' => $candidate,
                    'score' => 70,
                ];
            }
        }

        return $candidates;
    }

    private function labeledCandidate(string $value): ?string
    {
        $standalone = $this->standaloneCandidate($value);
        if ($standalone !== null) {
            return $standalone;
        }

        if (preg_match(
            '/^\s*([A-Z0-9][A-Z0-9.\/_-]*[A-Z0-9])\s+LAMPIRAN\b/iu',
            $value,
            $matches,
        ) === 1) {
            return $this->standaloneCandidate((string) $matches[1]);
        }

        return null;
    }

    private function standaloneCandidate(string $value): ?string
    {
        $normalized = trim($value, " \t\n\r\0\x0B:;,");
        $normalized = (string) preg_replace('/\s*([.\/_-])\s*/u', '$1', $normalized);
        $candidate = $this->validCandidate($normalized);

        return $candidate === $normalized ? $candidate : null;
    }

    private function isBodyBoundary(string $line): bool
    {
        return preg_match(
            '/^(?:MENIMBANG|DASAR|MENUGASKAN|KEPADA|UNTUK)\b/iu',
            $line,
        ) === 1;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isMemoContext(array $lines, int $index): bool
    {
        $context = implode(' ', array_slice(
            $lines,
            max(0, $index - 2),
            5,
        ));

        return preg_match('/\b(?:MEMO(?:\s+INTERNAL)?|NOTA\s+DINAS)\b/iu', $context) === 1
            || preg_match('/(?:^|\/)(?:PMK|UU)(?:[.\/_-]|$)/iu', $lines[$index]) === 1;
    }

    /**
     * @param  list<array{value: string, score: int}>  $candidates
     */
    private function resolveCandidates(array $candidates): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $highestScore = max(array_column($candidates, 'score'));
        $values = array_values(array_unique(array_map(
            fn(array $candidate): string => $candidate['value'],
            array_filter(
                $candidates,
                fn(array $candidate): bool => $candidate['score'] === $highestScore,
            ),
        )));

        if (count($values) > 1) {
            throw new SptOfficialDocumentException(
                'Ditemukan lebih dari satu nomor yang mungkin merupakan Nomor Naskah. Periksa kembali PDF yang diunggah.'
            );
        }

        return $values[0];
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
                || strlen($candidate) < 2
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
