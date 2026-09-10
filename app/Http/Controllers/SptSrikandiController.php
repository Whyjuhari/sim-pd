<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\SptSrikandiWorkflow;
use App\Services\Documents\TravelPdfDocumentService;
use App\Services\TravelStatusTransition;
use App\Services\Uploads\SptSrikandiDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SptSrikandiController extends Controller
{
    public function __construct(
        private readonly TravelPdfDocumentService $documents,
        private readonly SptSrikandiDocumentStorage $storage,
        private readonly TravelStatusTransition $statusTransition,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                SptSrikandiWorkflow::STATUS_DRAFT,
                SptSrikandiWorkflow::STATUS_WAITING,
                SptSrikandiWorkflow::STATUS_UPLOADED,
                SptSrikandiWorkflow::STATUS_PUBLISHED,
            ])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        $workflows = SptSrikandiWorkflow::query()
            ->with(['travels' => fn($query) => $query->with('pegawai')->orderBy('id')])
            ->when($status !== '', fn($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('travels', function ($query) use ($search): void {
                        $query->where('spt_internal_reference', 'like', "%{$search}%")
                            ->orWhere('kota_tujuan', 'like', "%{$search}%")
                            ->orWhereHas('pegawai', fn($query) => $query
                                ->where('nama_lengkap', 'like', "%{$search}%")
                                ->orWhere('nip', 'like', "%{$search}%"));
                    });
                });
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('spt-srikandi.index', [
            'workflows' => $workflows,
            'filters' => ['q' => $search, 'status' => $status],
        ]);
    }

    public function show(string $sptGroupId): View
    {
        return view('spt-srikandi.show', [
            'workflow' => $this->workflow($sptGroupId),
        ]);
    }

    public function markSent(Request $request, string $sptGroupId): RedirectResponse
    {
        $workflow = $this->workflow($sptGroupId);
        $travels = $workflow->travels;
        $travel = $travels->firstOrFail();

        if (
            $workflow->status !== SptSrikandiWorkflow::STATUS_DRAFT
            || ! $travels->every(
                fn(PerjalananDinas $item): bool =>
                $item->spt_number_mode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                    && $item->status === PerjalananDinas::STATUS_DRAFT
            )
        ) {
            throw ValidationException::withMessages([
                'spt' => 'SPT tidak dapat ditandai dikirim karena statusnya sudah berubah.',
            ]);
        }

        $archive = $this->storage->archiveDraft(
            $sptGroupId,
            $this->documents->suratTugas($travel)
        );

        try {
            DB::transaction(function () use ($sptGroupId, $archive, $request): void {
                $workflow = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $travels = PerjalananDinas::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->get();

                if (
                    $workflow->status !== SptSrikandiWorkflow::STATUS_DRAFT
                    || $travels->isEmpty()
                    || ! $travels->every(
                        fn(PerjalananDinas $travel): bool =>
                        $travel->spt_number_mode === PerjalananDinas::NUMBER_MODE_EXTERNAL
                            && $travel->status === PerjalananDinas::STATUS_DRAFT
                    )
                ) {
                    throw ValidationException::withMessages([
                        'spt' => 'SPT tidak dapat ditandai dikirim karena statusnya sudah berubah.',
                    ]);
                }

                $workflow->update([
                    'status' => SptSrikandiWorkflow::STATUS_WAITING,
                    'draft_pdf_path' => $archive['path'],
                    'draft_pdf_sha256' => $archive['sha256'],
                    'submitted_by' => (int) $request->user()->id,
                    'submitted_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $this->storage->delete($archive['path']);
            throw $exception;
        }

        return redirect()
            ->route('spt-srikandi.show', ['sptGroupId' => $sptGroupId])
            ->with('success', 'Draft diarsipkan dan ditandai telah dikirim ke Srikandi.');
    }

    public function upload(Request $request, string $sptGroupId): RedirectResponse
    {
        $data = $request->validate([
            'official_pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $workflow = $this->workflow($sptGroupId);
        if (! in_array($workflow->status, [
            SptSrikandiWorkflow::STATUS_WAITING,
            SptSrikandiWorkflow::STATUS_UPLOADED,
        ], true)) {
            throw ValidationException::withMessages([
                'official_pdf' => 'PDF resmi hanya dapat diunggah setelah draft ditandai dikirim.',
            ]);
        }

        $stored = $this->storage->storeOfficial($data['official_pdf'], $sptGroupId);
        $oldPath = null;

        try {
            DB::transaction(function () use (
                $sptGroupId,
                $stored,
                $request,
                &$oldPath
            ): void {
                $workflow = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $travels = PerjalananDinas::query()
                    ->where('spt_group_id', $sptGroupId)
                    ->lockForUpdate()
                    ->get();

                if (
                    ! in_array($workflow->status, [
                        SptSrikandiWorkflow::STATUS_WAITING,
                        SptSrikandiWorkflow::STATUS_UPLOADED,
                    ], true) || $travels->isEmpty()
                    || ! $travels->every(
                        fn(PerjalananDinas $travel): bool =>
                        $travel->status === PerjalananDinas::STATUS_DRAFT
                    )
                ) {
                    throw ValidationException::withMessages([
                        'official_pdf' => 'PDF resmi tidak dapat disimpan karena status SPT sudah berubah.',
                    ]);
                }

                $checksumUsed = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', '!=', $sptGroupId)
                    ->where('official_pdf_sha256', $stored['sha256'])
                    ->lockForUpdate()
                    ->first() !== null;
                if ($checksumUsed) {
                    throw ValidationException::withMessages([
                        'official_pdf' => 'PDF yang sama sudah digunakan oleh SPT lain.',
                    ]);
                }

                $oldPath = $workflow->official_pdf_path;
                $workflow->update([
                    'status' => SptSrikandiWorkflow::STATUS_UPLOADED,
                    'official_pdf_path' => $stored['path'],
                    'official_original_name' => $stored['original_name'],
                    'official_mime_type' => $stored['mime_type'],
                    'official_size_bytes' => $stored['size'],
                    'official_pdf_sha256' => $stored['sha256'],
                    'uploaded_by' => (int) $request->user()->id,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $this->storage->delete($stored['path']);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $stored['path']) {
            $this->storage->delete($oldPath);
        }

        return redirect()
            ->route('spt-srikandi.show', ['sptGroupId' => $sptGroupId])
            ->with('success', 'PDF resmi tersimpan. Periksa dokumen sebelum diterbitkan.');
    }

    public function document(string $sptGroupId): BinaryFileResponse
    {
        $workflow = $this->workflow($sptGroupId);
        abort_unless(in_array($workflow->status, [
            SptSrikandiWorkflow::STATUS_UPLOADED,
            SptSrikandiWorkflow::STATUS_PUBLISHED,
        ], true), 404);

        $path = $this->storage->verifiedAbsolutePath(
            $workflow->official_pdf_path,
            $workflow->official_pdf_sha256
        );
        abort_if(! $path, 404, 'PDF resmi Srikandi tidak ditemukan atau tidak valid.');

        $reference = $workflow->travels->first()?->spt_internal_reference ?: 'SPT_Srikandi';
        $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $reference)
            ?: 'SPT_Srikandi';

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="SPT_' . $safeReference . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function draftDocument(string $sptGroupId): BinaryFileResponse
    {
        $workflow = $this->workflow($sptGroupId);
        $path = $this->storage->verifiedAbsolutePath(
            $workflow->draft_pdf_path,
            $workflow->draft_pdf_sha256
        );
        abort_if(! $path, 404, 'Arsip PDF draft tidak ditemukan atau tidak valid.');

        $reference = $workflow->travels->first()?->spt_internal_reference ?: 'SPT';
        $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $reference) ?: 'SPT';

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Draft_SPT_' . $safeReference . '.pdf"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function publish(Request $request, string $sptGroupId): RedirectResponse
    {
        DB::transaction(function () use ($request, $sptGroupId): void {
            $workflow = SptSrikandiWorkflow::query()
                ->where('spt_group_id', $sptGroupId)
                ->lockForUpdate()
                ->firstOrFail();
            $travels = PerjalananDinas::query()
                ->where('spt_group_id', $sptGroupId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $officialPath = $this->storage->verifiedAbsolutePath(
                $workflow->official_pdf_path,
                $workflow->official_pdf_sha256
            );

            if (
                $workflow->status !== SptSrikandiWorkflow::STATUS_UPLOADED
                || ! $officialPath
                || $travels->isEmpty()
                || ! $travels->every(
                    fn(PerjalananDinas $travel): bool =>
                    $travel->status === PerjalananDinas::STATUS_DRAFT
                )
            ) {
                throw ValidationException::withMessages([
                    'spt' => 'SPT belum siap diterbitkan atau statusnya sudah berubah.',
                ]);
            }

            foreach ($travels as $travel) {
                $this->statusTransition->apply(
                    $travel,
                    PerjalananDinas::STATUS_READY,
                    $request->user(),
                    note: 'SPT resmi dari Srikandi diterbitkan kepada Pegawai.'
                );
            }

            $workflow->update([
                'status' => SptSrikandiWorkflow::STATUS_PUBLISHED,
                'published_by' => (int) $request->user()->id,
                'published_at' => now(),
            ]);
        });

        return redirect()
            ->route('spt-srikandi.show', ['sptGroupId' => $sptGroupId])
            ->with('success', 'SPT resmi telah diterbitkan dan sekarang tersedia untuk Pegawai.');
    }

    private function workflow(string $sptGroupId): SptSrikandiWorkflow
    {
        return SptSrikandiWorkflow::query()
            ->with(['travels' => fn($query) => $query->with('pegawai')->orderBy('id')])
            ->where('spt_group_id', $sptGroupId)
            ->firstOrFail();
    }
}
