<?php

namespace App\Services\Documents;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SptOfficialNumberExtractor
{
    public function __construct(
        private readonly SptOfficialNumberParser $parser,
    ) {}

    public function extract(string $pdfPath): string
    {
        if (! is_file($pdfPath)) {
            throw new SptOfficialDocumentException('PDF resmi tidak ditemukan untuk dibaca.');
        }

        $binary = trim((string) config('sim_pd.documents.pdf_text.binary', 'pdftotext'));
        if ($binary === '') {
            throw new SptOfficialDocumentException('Pembaca teks PDF belum dikonfigurasi pada server.');
        }

        $process = new Process([
            $binary,
            '-q',
            '-layout',
            '-nopgbrk',
            '-enc',
            'UTF-8',
            $pdfPath,
            '-',
        ], base_path());
        $process->setTimeout((int) config('sim_pd.documents.pdf_text.timeout', 15));

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new SptOfficialDocumentException('Pembacaan PDF melewati batas waktu. Silakan coba lagi.', previous: $exception);
        } catch (ProcessFailedException $exception) {
            throw new SptOfficialDocumentException('Teks PDF resmi tidak dapat dibaca.', previous: $exception);
        }

        $text = $process->getOutput();
        if ($this->parser->hasUnresolvedDraftPlaceholders($text)) {
            throw new SptOfficialDocumentException(
                'PDF masih memuat parameter draft nomor atau tanda tangan. Unggah SPT yang sudah selesai diproses.'
            );
        }

        $number = $this->parser->parse($text);
        if ($number === null) {
            throw new SptOfficialDocumentException(
                'Nomor Naskah tidak ditemukan pada PDF. Pastikan file memiliki Nomor Naskah dan teksnya dapat dipilih.'
            );
        }

        return $number;
    }
}
