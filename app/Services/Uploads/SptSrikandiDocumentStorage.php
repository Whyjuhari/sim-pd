<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SptSrikandiDocumentStorage
{
    private const DIRECTORY = 'spt-srikandi';

    /** @return array{path: string, sha256: string} */
    public function archiveDraft(string $groupId, string $sourcePath): array
    {
        if (! is_file($sourcePath) || ! $this->looksLikePdf($sourcePath)) {
            throw new RuntimeException('PDF draft Surat Tugas tidak valid.');
        }

        $contents = file_get_contents($sourcePath);
        if (! is_string($contents)) {
            throw new RuntimeException('PDF draft Surat Tugas tidak dapat dibaca.');
        }

        $path = $this->directory($groupId).'/draft-'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('PDF draft Surat Tugas tidak dapat diarsipkan.');
        }

        return ['path' => $path, 'sha256' => hash('sha256', $contents)];
    }

    /** @return array{path: string, original_name: string, mime_type: string, size: int, sha256: string} */
    public function archiveConcept(string $groupId, int $versionNumber, string $sourcePath): array
    {
        if (! is_file($sourcePath) || ! $this->looksLikeDocx($sourcePath)) {
            throw new RuntimeException('File Word konsep SPT tidak valid.');
        }

        $contents = file_get_contents($sourcePath);
        if (! is_string($contents)) {
            throw new RuntimeException('File Word konsep SPT tidak dapat dibaca.');
        }

        $path = $this->directory($groupId).'/concepts/version-'.$versionNumber.'-'.Str::uuid().'.docx';
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('File Word konsep SPT tidak dapat disimpan.');
        }

        return [
            'path' => $path,
            'original_name' => 'Konsep_SPT_V'.$versionNumber.'.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    /** @return array{path: string, original_name: string, mime_type: string, size: int, sha256: string} */
    public function storeOfficial(UploadedFile $file, string $groupId, string $field = 'official_pdf'): array
    {
        if ($file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages([
                $field => 'Berkas harus berupa PDF yang valid.',
            ]);
        }

        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents) || ! $this->validPdfContents($contents)) {
            throw ValidationException::withMessages([
                $field => 'PDF rusak atau tidak memiliki struktur PDF yang valid.',
            ]);
        }

        if (preg_match('/\/Encrypt\b/', $contents) === 1) {
            throw ValidationException::withMessages([
                $field => 'PDF tidak boleh dilindungi kata sandi atau terenkripsi.',
            ]);
        }

        $path = $this->directory($groupId).'/official-'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('SPT yang sudah jadi tidak dapat disimpan.');
        }

        $originalName = trim(basename((string) $file->getClientOriginalName()));
        $originalName = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $originalName) ?: 'SPT.pdf';

        return [
            'path' => $path,
            'original_name' => Str::limit($originalName, 255, ''),
            'mime_type' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function verifiedAbsolutePath(?string $path, ?string $sha256 = null): ?string
    {
        if (! is_string($path) || ! $this->isManagedPath($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('local')->path($path);
        if (! $this->looksLikePdf($absolutePath)) {
            return null;
        }

        if ($sha256 && ! hash_equals($sha256, (string) hash_file('sha256', $absolutePath))) {
            return null;
        }

        return $absolutePath;
    }

    public function verifiedConceptAbsolutePath(?string $path, ?string $sha256 = null): ?string
    {
        if (! is_string($path) || ! $this->isManagedPath($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $absolutePath = Storage::disk('local')->path($path);
        if (! $this->looksLikeDocx($absolutePath)) {
            return null;
        }

        if ($sha256 && ! hash_equals($sha256, (string) hash_file('sha256', $absolutePath))) {
            return null;
        }

        return $absolutePath;
    }

    public function delete(?string $path): void
    {
        if (is_string($path) && $this->isManagedPath($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public function deleteWorkflowFiles(?string $draftPath, ?string $officialPath): void
    {
        $this->delete($draftPath);
        $this->delete($officialPath);
    }

    private function directory(string $groupId): string
    {
        if (! preg_match('/\A[0-9a-f-]{36}\z/i', $groupId)) {
            throw new RuntimeException('Identitas grup SPT tidak valid.');
        }

        return self::DIRECTORY.'/'.strtolower($groupId);
    }

    private function isManagedPath(string $path): bool
    {
        return str_starts_with(str_replace('\\', '/', $path), self::DIRECTORY.'/');
    }

    private function looksLikePdf(string $path): bool
    {
        $contents = file_get_contents($path);

        return is_string($contents) && $this->validPdfContents($contents);
    }

    private function looksLikeDocx(string $path): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false;
        } finally {
            $zip->close();
        }
    }

    private function validPdfContents(string $contents): bool
    {
        if (strlen($contents) < 16 || ! str_starts_with($contents, '%PDF-')) {
            return false;
        }

        $tail = substr($contents, -2048);

        return str_contains($tail, '%%EOF');
    }
}
