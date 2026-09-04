<?php

namespace App\Services\Documents;

use Closure;
use DateTimeInterface;
use JsonSerializable;
use RuntimeException;
use Stringable;
use Throwable;

class GeneratedPdfCache
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string|null>  $dependencies
     */
    public function remember(
        string $documentType,
        string $scope,
        array $payload,
        array $dependencies,
        Closure $generate
    ): string {
        $directory = $this->scopeDirectory($documentType, $scope);
        $this->ensureDirectory($directory);

        $fingerprint = $this->fingerprint($documentType, $payload, $dependencies);
        $cachedPath = $directory.DIRECTORY_SEPARATOR.$fingerprint.'.pdf';

        if ($this->isPdf($cachedPath)) {
            return $cachedPath;
        }

        $lockPath = $directory.DIRECTORY_SEPARATOR.'.generation.lock';
        $lock = fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException('Kunci pembuatan cache dokumen tidak dapat dibuka.');
        }

        $generatedPath = null;
        $stagedPath = null;

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Kunci pembuatan cache dokumen tidak dapat diperoleh.');
            }

            clearstatcache(true, $cachedPath);
            if ($this->isPdf($cachedPath)) {
                return $cachedPath;
            }

            $generatedPath = $generate();
            if (! is_string($generatedPath) || ! $this->isPdf($generatedPath)) {
                throw new RuntimeException('Generator tidak menghasilkan dokumen PDF yang valid.');
            }

            $stagedPath = $directory.DIRECTORY_SEPARATOR.'.'.$fingerprint.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (! copy($generatedPath, $stagedPath)) {
                throw new RuntimeException('Dokumen PDF tidak dapat dipindahkan ke cache privat.');
            }

            if (! $this->isPdf($stagedPath)) {
                throw new RuntimeException('Dokumen PDF pada cache privat tidak valid.');
            }

            if (is_file($cachedPath) && ! unlink($cachedPath)) {
                throw new RuntimeException('Cache dokumen lama tidak dapat diganti.');
            }

            if (! rename($stagedPath, $cachedPath)) {
                throw new RuntimeException('Cache dokumen tidak dapat diselesaikan.');
            }
            $stagedPath = null;

            @chmod($cachedPath, 0640);
            $this->writeMetadata($cachedPath, $documentType, $scope, $fingerprint, $dependencies);
            $this->removeStaleVersions($directory, $cachedPath);

            return $cachedPath;
        } catch (Throwable $exception) {
            if ($stagedPath && is_file($stagedPath)) {
                @unlink($stagedPath);
            }

            throw $exception;
        } finally {
            if ($generatedPath && is_file($generatedPath)) {
                @unlink($generatedPath);
            }

            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string|null>  $dependencies
     */
    public function fingerprint(string $documentType, array $payload, array $dependencies): string
    {
        $dependencyState = [];

        foreach (array_values(array_unique(array_filter($dependencies, 'is_string'))) as $path) {
            $dependencyState[] = [
                'path' => $this->portablePath($path),
                'sha256' => is_file($path) ? hash_file('sha256', $path) : null,
                'size' => is_file($path) ? filesize($path) : null,
            ];
        }

        return hash('sha256', json_encode([
            'cache_version' => (string) config('sim_pd.documents.cache.version', '1'),
            'document_type' => $documentType,
            'payload' => $this->normalize($payload),
            'dependencies' => $dependencyState,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function scopeDirectory(string $documentType, string $scope): string
    {
        $root = (string) config(
            'sim_pd.documents.cache.directory',
            storage_path('app/private/documents/generated')
        );

        return rtrim($root, '\\/')
            .DIRECTORY_SEPARATOR.$this->safeSegment($documentType)
            .DIRECTORY_SEPARATOR.$this->safeSegment($scope);
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value));
        $segment = trim((string) $segment, '.-');

        if ($segment === '') {
            throw new RuntimeException('Identitas cache dokumen tidak valid.');
        }

        return substr($segment, 0, 160);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder cache dokumen tidak dapat dibuat.');
        }
    }

    private function isPdf(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 5) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }

    /** @return mixed */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }

        return $value;
    }

    private function portablePath(string $path): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base.'/')
            ? substr($normalized, strlen($base) + 1)
            : basename($normalized);
    }

    /** @param array<int, string|null> $dependencies */
    private function writeMetadata(
        string $pdfPath,
        string $documentType,
        string $scope,
        string $fingerprint,
        array $dependencies
    ): void {
        $metadataPath = substr($pdfPath, 0, -4).'.json';
        $temporaryPath = $metadataPath.'.'.bin2hex(random_bytes(4)).'.tmp';
        $metadata = json_encode([
            'document_type' => $documentType,
            'scope' => $scope,
            'fingerprint' => $fingerprint,
            'generated_at' => now()->toIso8601String(),
            'size' => filesize($pdfPath),
            'dependencies' => array_values(array_map(
                fn (?string $path): ?string => is_string($path) ? $this->portablePath($path) : null,
                $dependencies
            )),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($temporaryPath, $metadata, LOCK_EX) === false) {
            return;
        }

        @chmod($temporaryPath, 0640);
        if (is_file($metadataPath)) {
            @unlink($metadataPath);
        }
        if (! @rename($temporaryPath, $metadataPath)) {
            @unlink($temporaryPath);
        }
    }

    private function removeStaleVersions(string $directory, string $currentPdf): void
    {
        $currentBase = pathinfo($currentPdf, PATHINFO_FILENAME);

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.{pdf,json}', GLOB_BRACE) ?: [] as $path) {
            if (pathinfo($path, PATHINFO_FILENAME) !== $currentBase && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
