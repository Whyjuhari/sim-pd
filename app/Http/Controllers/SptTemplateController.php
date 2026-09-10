<?php

namespace App\Http\Controllers;

use App\Models\SptTemplate;
use App\Services\Documents\GeneratedPdfCache;
use App\Services\Documents\PdfConverter;
use App\Support\SptTemplateVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;
use ZipArchive;

class SptTemplateController extends Controller
{
    private const REQUIRED_PLACEHOLDERS = [
        'nomor_pegawai',
        'kepada_pegawai',
        'nama_pegawai',
        'nip',
        'pangkat_golongan',
        'jabatan',
        'nomor_surat',
        'menimbang',
        'nomor_memo',
        'perihal_memo',
        'tanggal_memo',
        'akun',
        'tanggal_pelaksanaan',
        'tanggal_surat',
        'untuk_kegiatan',
    ];

    private const STORAGE_DIR = 'spt-templates';
    private const MAX_FILE_SIZE_KB = 5120;

    public function index(): View
    {
        $templates = SptTemplate::query()
            ->with('creator:id,nama_lengkap')
            ->withCount('perjalananDinas')
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return view('spt-templates.index', compact('templates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'file' => [
                'required',
                'file',
                'mimes:docx',
                'max:' . self::MAX_FILE_SIZE_KB,
            ],
        ]);

        $file = $request->file('file');
        $absoluteTmpPath = $file->getRealPath();

        if ($absoluteTmpPath === false || ! is_file($absoluteTmpPath)) {
            throw ValidationException::withMessages([
                'file' => 'File tidak dapat dibaca.',
            ]);
        }

        $missing = $this->validatePlaceholders($absoluteTmpPath);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Template tidak memiliki placeholder berikut: ' . implode(', ', $missing) . '.',
            ]);
        }

        $uuid = (string) Str::uuid();
        $filename = $uuid . '.docx';
        $relativePath = self::STORAGE_DIR . '/' . $filename;

        $file->storeAs(
            self::STORAGE_DIR,
            $filename,
            'local'
        );

        $storedPath = Storage::disk('local')->path($relativePath);
        $fileHash = hash_file('sha256', $storedPath);

        SptTemplate::query()->create([
            'nama' => $data['nama'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'file_path' => $relativePath,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_sha256' => $fileHash,
            'is_active' => true,
            'is_default' => false,
            'created_by' => $request->user()->id,
        ]);

        $this->generateThumbnailAfterResponse($uuid);

        return redirect()
            ->route('spt-templates.index')
            ->with('success', 'Template SPT berhasil diunggah.');
    }

    public function setDefault(SptTemplate $template): RedirectResponse
    {
        abort_unless($template->is_active, 422, 'Template tidak aktif dan tidak dapat dijadikan default.');

        SptTemplate::query()->where('is_default', true)->update(['is_default' => false]);

        $template->update(['is_default' => true]);

        return redirect()
            ->route('spt-templates.index')
            ->with('success', "Template \"{$template->nama}\" dijadikan template default.");
    }

    public function toggleActive(SptTemplate $template): RedirectResponse
    {
        if ($template->is_active && $template->is_default) {
            $activeCount = SptTemplate::query()->where('is_active', true)->count();

            if ($activeCount <= 1) {
                throw ValidationException::withMessages([
                    'template' => 'Tidak dapat menonaktifkan template terakhir yang masih aktif.',
                ]);
            }
        }

        $template->update(['is_active' => ! $template->is_active]);

        $status = $template->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return redirect()
            ->route('spt-templates.index')
            ->with('success', "Template \"{$template->nama}\" berhasil {$status}.");
    }

    public function destroy(SptTemplate $template): RedirectResponse
    {
        $usageCount = $template->usageCount();

        if ($usageCount > 0) {
            throw ValidationException::withMessages([
                'template' => "Template masih digunakan oleh {$usageCount} Surat Tugas dan tidak dapat dihapus. Gunakan nonaktifkan sebagai gantinya.",
            ]);
        }

        if ($template->is_default) {
            throw ValidationException::withMessages([
                'template' => 'Template default tidak dapat dihapus. Jadikan template lain sebagai default terlebih dahulu.',
            ]);
        }

        $filePath = $template->file_path;
        $template->deleteThumbnailFromDisk();
        $template->delete();

        if ($filePath) {
            $absolutePath = Storage::disk('local')->path($filePath);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        return redirect()
            ->route('spt-templates.index')
            ->with('success', 'Template berhasil dihapus.');
    }

    public function download(SptTemplate $template)
    {
        abort_unless($template->existsOnDisk(), 404, 'File template tidak ditemukan.');

        return response()->download(
            $template->absolutePath(),
            $template->original_filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]
        );
    }

    public function thumbnail(SptTemplate $template)
    {
        abort_unless($template->existsThumbnail(), 404, 'Thumbnail tidak tersedia.');

        $diskPath = $template->thumbnailDiskPath();

        if (! $diskPath || ! is_file($diskPath)) {
            abort(404, 'Thumbnail tidak tersedia.');
        }

        $response = response()->file($diskPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    public function builtInThumbnail(string $variant, GeneratedPdfCache $cache)
    {
        abort_unless(SptTemplateVariant::exists($variant), 404, 'Template bawaan tidak ditemukan.');

        $template = SptTemplateVariant::get($variant);
        $templatePath = SptTemplateVariant::path($variant);

        abort_unless(is_file($templatePath), 404, 'File template bawaan tidak ditemukan.');

        try {
            $pdfPath = $cache->remember(
                'spt-template-thumbnail',
                'built-in-'.$variant,
                [
                    'variant' => $variant,
                    'label' => $template['label'] ?? $variant,
                ],
                [
                    $templatePath,
                    (new \ReflectionClass(PdfConverter::class))->getFileName() ?: null,
                ],
                function () use ($templatePath): string {
                    $converter = new PdfConverter(
                        config('sim_pd.documents.libreoffice.binary'),
                        (int) config('sim_pd.documents.libreoffice.timeout', 60),
                    );

                    return $converter->convert(
                        $templatePath,
                        config('sim_pd.documents.pdf_dir'),
                    );
                },
            );
        } catch (Throwable $exception) {
            report($exception);
            abort(404, 'Pratinjau template bawaan belum tersedia.');
        }

        $response = response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(86400);

        return $response;
    }

    private function generateThumbnailAfterResponse(string $uuid): void
    {
        if (! config('sim_pd.documents.libreoffice.binary')) {
            return;
        }

        app()->terminating(function () use ($uuid): void {
            try {
                $this->generateThumbnail($uuid);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function generateThumbnail(string $uuid): void
    {
        $docxRelative = self::STORAGE_DIR . '/' . $uuid . '.docx';
        $docxAbsolute = Storage::disk('local')->path($docxRelative);

        if (! is_file($docxAbsolute)) {
            return;
        }

        $thumbsDir = Storage::disk('local')->path(self::STORAGE_DIR . '/thumbs');

        if (! is_dir($thumbsDir)) {
            mkdir($thumbsDir, 0775, true);
        }

        $converter = new PdfConverter(
            config('sim_pd.documents.libreoffice.binary'),
            (int) config('sim_pd.documents.libreoffice.timeout', 60),
        );

        $pdfPath = $converter->convert($docxAbsolute, $thumbsDir);

        $pdfRelative = self::STORAGE_DIR . '/thumbs/' . basename($pdfPath);
        $sha256 = is_file($pdfPath) ? hash_file('sha256', $pdfPath) : null;

        SptTemplate::query()
            ->where('file_path', $docxRelative)
            ->update([
                'thumbnail_path' => $pdfRelative,
                'thumbnail_sha256' => $sha256,
            ]);
    }

    /**
     * Buka DOCX sebagai ZIP dan cari placeholder di word/document.xml.
     *
     * @return list<string>
     */
    private function validatePlaceholders(string $docxPath): array
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($docxPath);

        if ($openResult !== true) {
            throw ValidationException::withMessages([
                'file' => 'File tidak dapat dibuka sebagai DOCX yang valid.',
            ]);
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false) {
                throw ValidationException::withMessages([
                    'file' => 'File DOCX tidak memiliki word/document.xml.',
                ]);
            }

            $missing = [];

            foreach (self::REQUIRED_PLACEHOLDERS as $placeholder) {
                if (! str_contains($xml, $placeholder)) {
                    $missing[] = $placeholder;
                }
            }

            return $missing;
        } finally {
            $zip->close();
        }
    }
}
