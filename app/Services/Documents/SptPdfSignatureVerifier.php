<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class SptPdfSignatureVerifier
{
    public function assertValid(string $pdfPath): void
    {
        if (! (bool) config('sim_pd.documents.pdf_signature.enabled', true)) {
            return;
        }

        if (! is_file($pdfPath)) {
            throw new SptOfficialDocumentException('PDF resmi tidak ditemukan untuk diperiksa.');
        }

        $result = $this->execute([$pdfPath, ...$this->verificationOptions()]);

        if (($result['ok'] ?? false) === true) {
            return;
        }

        if (in_array(($result['code'] ?? null), ['service_unavailable', 'verification_error'], true)) {
            Log::warning('Pemeriksaan tanda tangan elektronik PDF gagal.', [
                'code' => $result['code'] ?? null,
                'exception' => $result['exception'] ?? null,
            ]);
        }

        throw new SptOfficialDocumentException($this->userMessage((string) ($result['code'] ?? '')));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        if (! (bool) config('sim_pd.documents.pdf_signature.enabled', true)) {
            return ['ok' => false, 'message' => 'Pemeriksaan TTE dinonaktifkan'];
        }

        try {
            $result = $this->execute(['--health-check']);

            return ($result['ok'] ?? false) === true
                ? ['ok' => true, 'message' => 'Pemeriksa TTE tersedia']
                : ['ok' => false, 'message' => 'Pemeriksa TTE belum siap'];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Pemeriksa TTE belum siap'];
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function execute(array $arguments): array
    {
        $binary = trim((string) config('sim_pd.documents.pdf_signature.python_binary', 'python'));
        $script = (string) config(
            'sim_pd.documents.pdf_signature.script',
            base_path('scripts/verify_pdf_signature.py')
        );

        if ($binary === '' || ! is_file($script)) {
            throw new SptOfficialDocumentException($this->userMessage('service_unavailable'));
        }

        $process = new Process([$binary, $script, ...$arguments], base_path());
        $process->setTimeout(max(
            1,
            (int) config('sim_pd.documents.pdf_signature.timeout', 30)
        ));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new SptOfficialDocumentException(
                'Pemeriksaan tanda tangan elektronik melewati batas waktu. Silakan coba lagi.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            Log::warning('Proses pemeriksa tanda tangan elektronik tidak dapat dijalankan.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new SptOfficialDocumentException(
                $this->userMessage('service_unavailable'),
                previous: $exception,
            );
        }

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result)) {
            Log::warning('Keluaran pemeriksa tanda tangan elektronik tidak dapat dibaca.', [
                'exit_code' => $process->getExitCode(),
                'stdout' => mb_substr(trim($process->getOutput()), 0, 500),
                'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 500),
            ]);

            throw new SptOfficialDocumentException($this->userMessage('service_unavailable'));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function verificationOptions(): array
    {
        $options = [];

        if ((bool) config('sim_pd.documents.pdf_signature.require_trusted', false)) {
            $options[] = '--require-trusted';
        }

        if ((bool) config('sim_pd.documents.pdf_signature.allow_fetching', false)) {
            $options[] = '--allow-fetching';
        }

        foreach ((array) config('sim_pd.documents.pdf_signature.trust_roots', []) as $trustRoot) {
            if (is_string($trustRoot) && trim($trustRoot) !== '') {
                $options[] = '--trust-root';
                $options[] = trim($trustRoot);
            }
        }

        return $options;
    }

    private function userMessage(string $code): string
    {
        return match ($code) {
            'no_signature' => 'PDF belum memiliki tanda tangan elektronik yang dapat diverifikasi. Unggah PDF final hasil proses SRIKANDI.',
            'invalid_signature' => 'Tanda tangan elektronik PDF tidak valid atau dokumen telah berubah setelah ditandatangani.',
            'untrusted_signature' => 'Sertifikat tanda tangan elektronik PDF belum dipercaya oleh server. Hubungi Admin untuk memeriksa sertifikat root BSrE.',
            'verification_error' => 'Tanda tangan elektronik pada PDF tidak dapat diperiksa. Pastikan file merupakan PDF final asli dan tidak rusak.',
            default => 'Layanan pemeriksa tanda tangan elektronik belum siap pada server. Hubungi Admin.',
        };
    }
}
