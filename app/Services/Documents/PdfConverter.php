<?php

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PdfConverter
{
    public function __construct(
        private readonly string $binaryPath,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function convert(string $inputPath, string $outputDirectory): string
    {
        if (! is_file($inputPath)) {
            throw new RuntimeException('File sumber dokumen tidak ditemukan: ' . $inputPath);
        }

        if (! is_file($this->binaryPath)) {
            throw new RuntimeException('LibreOffice tidak ditemukan pada: ' . $this->binaryPath);
        }

        $this->ensureDirectory($outputDirectory);
        $expectedPdf = rtrim($outputDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . pathinfo($inputPath, PATHINFO_FILENAME) . '.pdf';

        if (is_file($expectedPdf)) {
            unlink($expectedPdf);
        }

        if (PHP_SAPI !== 'cli') {
            return $this->convertThroughCli($inputPath, $outputDirectory, $expectedPdf);
        }

        $profileRoot = function_exists('storage_path')
            ? storage_path('app/private/documents/libreoffice-profiles')
            : sys_get_temp_dir();
        $this->ensureDirectory($profileRoot);
        $profileDirectory = $profileRoot . DIRECTORY_SEPARATOR . 'sim-pd-lo-profile-' . bin2hex(random_bytes(6));
        $this->ensureDirectory($profileDirectory);
        $profileUri = 'file:///' . str_replace('\\', '/', ltrim($profileDirectory, '\\/'));
        $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
        $filter = $extension === 'xlsx' ? 'pdf:calc_pdf_Export' : 'pdf:writer_pdf_Export';

        $arguments = [
            $this->binaryPath,
            '-env:UserInstallation=' . $profileUri,
            '--headless',
            '--nologo',
            '--nodefault',
            '--nolockcheck',
            '--nofirststartwizard',
            '--convert-to',
            $filter,
            '--outdir',
            $outputDirectory,
            $inputPath,
        ];

        $process = PHP_OS_FAMILY === 'Windows'
            ? Process::fromShellCommandline(
                implode(' ', array_map('escapeshellarg', $arguments)),
                dirname($this->binaryPath)
            )
            : new Process($arguments, dirname($this->binaryPath));
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException('Konversi PDF melewati batas waktu ' . $this->timeoutSeconds . ' detik.', previous: $exception);
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException('Konversi PDF gagal: ' . $process->getErrorOutput() . $process->getOutput(), previous: $exception);
        } finally {
            $this->removeDirectory($profileDirectory);
        }

        if (! is_file($expectedPdf)) {
            throw new RuntimeException('LibreOffice selesai tetapi file PDF tidak ditemukan.');
        }

        return $expectedPdf;
    }

    private function convertThroughCli(string $inputPath, string $outputDirectory, string $expectedPdf): string
    {
        $processTemp = storage_path('app/private/process-temp');
        $this->ensureDirectory($processTemp);
        $phpCliBinary = trim((string) config('sim_pd.documents.php_cli_binary', 'php'));

        if ($phpCliBinary === '') {
            throw new RuntimeException('Binary PHP CLI belum dikonfigurasi.');
        }

        $process = new Process([
            $phpCliBinary,
            base_path('artisan'),
            'sim-pd:convert-document',
            $inputPath,
            $outputDirectory,
        ], base_path(), [
            'TEMP' => $processTemp,
            'TMP' => $processTemp,
        ]);
        $process->setTimeout($this->timeoutSeconds + 15);

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException(
                'Konversi PDF melalui proses CLI melewati batas waktu ' . ($this->timeoutSeconds + 15) . ' detik.',
                previous: $exception,
            );
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(
                'Konversi PDF melalui proses CLI gagal: ' . $process->getErrorOutput() . $process->getOutput(),
                previous: $exception,
            );
        }

        if (! is_file($expectedPdf)) {
            throw new RuntimeException('Proses CLI selesai tetapi file PDF tidak ditemukan.');
        }

        return $expectedPdf;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Tidak dapat membuat folder: ' . $directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
