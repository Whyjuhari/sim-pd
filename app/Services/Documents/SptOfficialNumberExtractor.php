<?php

namespace App\Services\Documents;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SptOfficialNumberExtractor
{
    public function __construct(
        private readonly SptOfficialNumberParser $parser,
        private readonly SptPdfSignatureVerifier $signatureVerifier,
    ) {}

    public function extract(string $pdfPath): string
    {
        if (! is_file($pdfPath)) {
            throw new SptOfficialDocumentException('PDF resmi tidak ditemukan untuk dibaca.');
        }

        $this->signatureVerifier->assertValid($pdfPath);

        $binary = trim((string) config('sim_pd.documents.pdf_text.binary', 'pdftotext'));
        if ($binary === '') {
            throw new SptOfficialDocumentException('Pembaca teks PDF belum dikonfigurasi pada server.');
        }

        $texts = [$this->readText($binary, $pdfPath, preserveLayout: true)];
        $number = $this->parser->parse($texts[0]);

        if ($number === null) {
            $texts[] = $this->readText($binary, $pdfPath, preserveLayout: false);
            $number = $this->parser->parse($texts[1]);
        }

        if ($number === null) {
            if (collect($texts)->contains(
                fn(string $text): bool => $this->parser->hasUnresolvedNumberPlaceholders($text)
            )) {
                throw new SptOfficialDocumentException(
                    'PDF masih memuat parameter Nomor Naskah dan nomor resminya belum dapat dibaca.'
                );
            }

            throw new SptOfficialDocumentException(
                'Nomor Naskah tidak ditemukan pada PDF. Pastikan PDF resmi memiliki Nomor Naskah dan teksnya dapat dipilih.'
            );
        }

        return $number;
    }

    private function readText(string $binary, string $pdfPath, bool $preserveLayout): string
    {
        $arguments = [$binary, '-q'];
        $arguments[] = $preserveLayout ? '-layout' : '-raw';
        array_push($arguments, '-nopgbrk', '-enc', 'UTF-8', $pdfPath, '-');

        $process = new Process($arguments, base_path());
        $process->setTimeout((int) config('sim_pd.documents.pdf_text.timeout', 15));

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new SptOfficialDocumentException(
                'Pembacaan PDF melewati batas waktu. Silakan coba lagi.',
                previous: $exception,
            );
        } catch (ProcessFailedException $exception) {
            throw new SptOfficialDocumentException(
                'Teks PDF resmi tidak dapat dibaca.',
                previous: $exception,
            );
        }

        return $process->getOutput();
    }
}
