<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\SptSrikandiVersion;
use App\Models\SptSrikandiWorkflow;
use App\Services\Documents\SptOfficialDocumentException;
use App\Services\Documents\SptOfficialNumberExtractor;
use App\Services\Documents\TravelPdfDocumentService;
use App\Services\TravelStatusTransition;
use App\Services\Uploads\SptSrikandiDocumentStorage;
use App\Support\OfficerSptNavigation;
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
        private readonly SptOfficialNumberExtractor $officialNumberExtractor,
        private readonly SptSrikandiDocumentStorage $storage,
        private readonly TravelStatusTransition $statusTransition,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', Rule::in(['action', 'waiting', 'published', 'all'])],
            'status' => ['nullable', Rule::in([
                SptSrikandiWorkflow::STATUS_DRAFT,
                SptSrikandiWorkflow::STATUS_WAITING,
                SptSrikandiWorkflow::STATUS_REVISION,
                SptSrikandiWorkflow::STATUS_UPLOADED,
                SptSrikandiWorkflow::STATUS_PUBLISHED,
            ])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        if (($filters['tab'] ?? '') === 'published' || $status === SptSrikandiWorkflow::STATUS_PUBLISHED) {
            return redirect()->route('dashboard.officer', ['q' => $search]);
        }
        if ($request->has('tab')) {
            return redirect()->route('spt-srikandi.index', [
                'q' => $search,
                'status' => $status ?: (($filters['tab'] ?? '') === 'waiting' ? SptSrikandiWorkflow::STATUS_WAITING : ''),
            ]);
        }

        $workflows = SptSrikandiWorkflow::query()
            ->with(['travels' => fn($query) => $query->with('pegawai')->orderBy('id')])
            ->where('status', '!=', SptSrikandiWorkflow::STATUS_PUBLISHED)
            ->when($status !== '', fn($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('external_number', 'like', "%{$search}%")
                        ->orWhereHas('travels', function ($query) use ($search): void {
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

    public function show(Request $request, string $sptGroupId): RedirectResponse
    {
        $this->workflow($sptGroupId);

        return redirect()->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request));
    }

    public function conceptDocument(Request $request, string $sptGroupId): BinaryFileResponse
    {
        $workflow = $this->workflow($sptGroupId);
        $conceptIsEditable = in_array($workflow->status, [
            SptSrikandiWorkflow::STATUS_DRAFT,
            SptSrikandiWorkflow::STATUS_REVISION,
        ], true);

        if ($request->query->has('version')) {
            $data = $request->validate([
                'version' => ['required', 'integer', 'min:1'],
            ]);
            $requestedVersion = $workflow->versions
                ->firstWhere('version_number', (int) $data['version']);
            if (! $conceptIsEditable && $requestedVersion?->submitted_at === null) {
                $requestedVersion = null;
            }
            $requestedPath = $requestedVersion
                ? $this->storage->verifiedConceptAbsolutePath(
                    $requestedVersion->docx_path,
                    $requestedVersion->docx_sha256
                )
                : null;
            abort_if(! $requestedVersion || ! $requestedPath, 404, 'Arsip file Word tidak ditemukan atau tidak valid.');

            return $this->conceptResponse($workflow, $requestedVersion, $requestedPath);
        }

        $version = $conceptIsEditable
            ? $workflow->versions
                ->sortByDesc('version_number')
                ->first(fn(SptSrikandiVersion $item): bool => $item->submitted_at === null)
            : null;

        if ($version) {
            $path = $this->storage->verifiedConceptAbsolutePath(
                $version->docx_path,
                $version->docx_sha256
            );

            if ($path) {
                return $this->conceptResponse($workflow, $version, $path);
            }

            $this->storage->delete($version->docx_path);
            $version->delete();
        }

        if (! $conceptIsEditable) {
            $version = $workflow->versions
                ->sortByDesc('version_number')
                ->first(fn(SptSrikandiVersion $item): bool => $item->submitted_at !== null);
            $path = $version
                ? $this->storage->verifiedConceptAbsolutePath($version->docx_path, $version->docx_sha256)
                : null;
            abort_if(! $version || ! $path, 404, 'Arsip file Word konsep tidak ditemukan atau tidak valid.');

            return $this->conceptResponse($workflow, $version, $path);
        }

        $temporaryPath = $this->documents->suratTugasDocx($workflow->travels->firstOrFail());

        try {
            $nextVersion = ((int) $workflow->versions->max('version_number')) + 1;
            $stored = $this->storage->archiveConcept($sptGroupId, $nextVersion, $temporaryPath);

            try {
                $version = DB::transaction(function () use ($request, $workflow, $nextVersion, $stored): SptSrikandiVersion {
                    $lockedWorkflow = SptSrikandiWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
                    if (! in_array($lockedWorkflow->status, [
                        SptSrikandiWorkflow::STATUS_DRAFT,
                        SptSrikandiWorkflow::STATUS_REVISION,
                    ], true)) {
                        throw ValidationException::withMessages([
                            'spt' => 'File Word tidak dapat disiapkan karena status SPT sudah berubah.',
                        ]);
                    }

                    $existing = SptSrikandiVersion::query()
                        ->where('workflow_id', $lockedWorkflow->id)
                        ->whereNull('submitted_at')
                        ->lockForUpdate()
                        ->latest('version_number')
                        ->first();
                    if ($existing) {
                        return $existing;
                    }

                    return SptSrikandiVersion::query()->create([
                        'workflow_id' => $lockedWorkflow->id,
                        'version_number' => $nextVersion,
                        'docx_path' => $stored['path'],
                        'docx_original_name' => $stored['original_name'],
                        'docx_mime_type' => $stored['mime_type'],
                        'docx_size_bytes' => $stored['size'],
                        'docx_sha256' => $stored['sha256'],
                        'prepared_by' => (int) $request->user()->id,
                        'prepared_at' => now(),
                    ]);
                });
            } catch (Throwable $exception) {
                $this->storage->delete($stored['path']);
                throw $exception;
            }

            if ($version->docx_path !== $stored['path']) {
                $this->storage->delete($stored['path']);
            }

            $path = $this->storage->verifiedConceptAbsolutePath($version->docx_path, $version->docx_sha256);
            abort_if(! $path, 404, 'File Word konsep tidak ditemukan atau tidak valid.');

            return $this->conceptResponse($workflow, $version, $path);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function markSent(Request $request, string $sptGroupId): RedirectResponse
    {
        $request->validate([
            'confirmed_uploaded' => ['accepted'],
        ], [
            'confirmed_uploaded.accepted' => 'Centang konfirmasi setelah file Word benar-benar diunggah ke SRIKANDI.',
        ]);

        DB::transaction(function () use ($sptGroupId, $request): void {
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
                        SptSrikandiWorkflow::STATUS_DRAFT,
                        SptSrikandiWorkflow::STATUS_REVISION,
                    ], true)
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

                $version = SptSrikandiVersion::query()
                    ->where('workflow_id', $workflow->id)
                    ->whereNull('submitted_at')
                    ->lockForUpdate()
                    ->latest('version_number')
                    ->first();
                $conceptPath = $version
                    ? $this->storage->verifiedConceptAbsolutePath($version->docx_path, $version->docx_sha256)
                    : null;
                if (! $version || ! $conceptPath) {
                    throw ValidationException::withMessages([
                        'spt' => 'Unduh file Word terlebih dahulu sebelum menandainya sudah dikirim.',
                    ]);
                }

                $version->update([
                    'submitted_by' => (int) $request->user()->id,
                    'submitted_at' => now(),
                ]);

                $workflow->update([
                    'status' => SptSrikandiWorkflow::STATUS_WAITING,
                    'submitted_by' => (int) $request->user()->id,
                    'submitted_at' => now(),
                ]);
            });

        return redirect()
            ->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request))
            ->with('success', 'Konsep ditandai sudah dikirim. Sekarang tunggu sampai SPT selesai diproses.');
    }

    public function revision(Request $request, string $sptGroupId): RedirectResponse
    {
        $data = $request->validate([
            'revision_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $sptGroupId, $data): void {
            $workflow = SptSrikandiWorkflow::query()
                ->where('spt_group_id', $sptGroupId)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($workflow->status, [SptSrikandiWorkflow::STATUS_WAITING, SptSrikandiWorkflow::STATUS_UPLOADED], true)) {
                throw ValidationException::withMessages([
                    'revision_reason' => 'Perbaikan hanya dapat dicatat ketika SPT sedang menunggu diproses atau sudah diunggah.',
                ]);
            }

            $version = SptSrikandiVersion::query()
                ->where('workflow_id', $workflow->id)
                ->whereNotNull('submitted_at')
                ->lockForUpdate()
                ->latest('version_number')
                ->first();
            if (! $version) {
                throw ValidationException::withMessages([
                    'revision_reason' => 'Riwayat file Word yang dikirim tidak ditemukan.',
                ]);
            }

            $version->update([
                'revision_reason' => trim($data['revision_reason']),
                'revision_requested_by' => (int) $request->user()->id,
                'revision_requested_at' => now(),
            ]);
            $workflow->update(['status' => SptSrikandiWorkflow::STATUS_REVISION]);
        });

        return redirect()
            ->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request))
            ->with('success', 'SPT ditandai perlu diperbaiki. Riwayat file sebelumnya tetap tersimpan.');
    }

    public function upload(Request $request, string $sptGroupId): RedirectResponse
    {
        $data = $request->validate([
            'official_pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $workflow = $this->workflow($sptGroupId);
        if (! in_array($workflow->status, [
            SptSrikandiWorkflow::STATUS_DRAFT,
            SptSrikandiWorkflow::STATUS_REVISION,
            SptSrikandiWorkflow::STATUS_WAITING,
            SptSrikandiWorkflow::STATUS_UPLOADED,
        ], true)) {
            throw ValidationException::withMessages([
                'official_pdf' => 'SPT yang sudah jadi tidak dapat diunggah pada status saat ini.',
            ]);
        }

        $stored = $this->storage->storeOfficial($data['official_pdf'], $sptGroupId);
        $oldPath = null;

        try {
            $storedPath = $this->storage->verifiedAbsolutePath($stored['path'], $stored['sha256']);
            if (! $storedPath) {
                throw new \RuntimeException('PDF resmi tidak dapat diverifikasi setelah disimpan.');
            }

            $externalNumber = $this->officialNumberExtractor->extract($storedPath);
        } catch (SptOfficialDocumentException $exception) {
            $this->storage->delete($stored['path']);

            throw ValidationException::withMessages([
                'official_pdf' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            $this->storage->delete($stored['path']);
            report($exception);

            throw ValidationException::withMessages([
                'official_pdf' => 'PDF belum dapat diperiksa. Pastikan layanan pembaca PDF tersedia, lalu coba lagi.',
            ]);
        }

        try {
            DB::transaction(function () use (
                $sptGroupId,
                $stored,
                $externalNumber,
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
                        SptSrikandiWorkflow::STATUS_DRAFT,
                        SptSrikandiWorkflow::STATUS_REVISION,
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

                if (in_array($workflow->status, [SptSrikandiWorkflow::STATUS_DRAFT, SptSrikandiWorkflow::STATUS_REVISION], true)) {
                    $version = SptSrikandiVersion::query()
                        ->where('workflow_id', $workflow->id)
                        ->whereNull('submitted_at')
                        ->lockForUpdate()
                        ->latest('version_number')
                        ->first();
                    if ($version) {
                        $version->update([
                            'submitted_by' => (int) $request->user()->id,
                            'submitted_at' => now(),
                        ]);
                    }
                    if (! $workflow->submitted_at) {
                        $workflow->submitted_by = (int) $request->user()->id;
                        $workflow->submitted_at = now();
                    }
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

                $numberUsedByWorkflow = SptSrikandiWorkflow::query()
                    ->where('spt_group_id', '!=', $sptGroupId)
                    ->where('external_number', $externalNumber)
                    ->lockForUpdate()
                    ->exists();
                $numberUsedByTravel = PerjalananDinas::query()
                    ->where(function ($query) use ($externalNumber): void {
                        $query->where('spt_external_number', $externalNumber)
                            ->orWhere(function ($query) use ($externalNumber): void {
                                $query->where('spt_number_mode', PerjalananDinas::NUMBER_MODE_MANUAL)
                                    ->where('no_spt', $externalNumber);
                            });
                    })
                    ->where(function ($query) use ($sptGroupId): void {
                        $query->whereNull('spt_group_id')
                            ->orWhere('spt_group_id', '!=', $sptGroupId);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($numberUsedByWorkflow || $numberUsedByTravel) {
                    throw ValidationException::withMessages([
                        'official_pdf' => 'Nomor Naskah yang terbaca dari PDF sudah digunakan oleh SPT lain.',
                    ]);
                }

                $oldPath = $workflow->official_pdf_path;
                $workflow->update([
                    'status' => SptSrikandiWorkflow::STATUS_UPLOADED,
                    'external_number' => $externalNumber,
                    'official_pdf_path' => $stored['path'],
                    'official_original_name' => $stored['original_name'],
                    'official_mime_type' => $stored['mime_type'],
                    'official_size_bytes' => $stored['size'],
                    'official_pdf_sha256' => $stored['sha256'],
                    'uploaded_by' => (int) $request->user()->id,
                    'uploaded_at' => now(),
                ]);

                foreach ($travels as $travel) {
                    $travel->update([
                        'spt_external_number' => $externalNumber,
                        'spt_external_number_recorded_at' => now(),
                        'spt_external_number_recorded_by' => (int) $request->user()->id,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $this->storage->delete($stored['path']);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $stored['path']) {
            $this->storage->delete($oldPath);
        }

        return redirect()
            ->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request))
            ->with('open_official_preview', true)
            ->with('success', 'SPT berhasil diunggah. Nomor Naskah '.$externalNumber.' berhasil dibaca; cocokkan dengan PDF sebelum dibagikan.');
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
        abort_if(! $path, 404, 'SPT yang sudah jadi tidak ditemukan atau tidak valid.');

        $reference = $workflow->travels->first()?->spt_internal_reference ?: 'SPT';
        $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $reference)
            ?: 'SPT';

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
                || blank($workflow->external_number)
                || ! $officialPath
                || $travels->isEmpty()
                || ! $travels->every(
                    fn(PerjalananDinas $travel): bool =>
                    $travel->status === PerjalananDinas::STATUS_DRAFT
                        && $travel->spt_external_number === $workflow->external_number
                )
            ) {
                throw ValidationException::withMessages([
                    'spt' => 'SPT belum siap dibagikan atau statusnya sudah berubah.',
                ]);
            }

            foreach ($travels as $travel) {
                $this->statusTransition->apply(
                    $travel,
                    PerjalananDinas::STATUS_READY,
                    $request->user(),
                    note: 'SPT yang sudah selesai diproses dibagikan kepada Pegawai.'
                );
            }

            $workflow->update([
                'status' => SptSrikandiWorkflow::STATUS_PUBLISHED,
                'published_by' => (int) $request->user()->id,
                'published_at' => now(),
            ]);
        });

        return redirect()
            ->route('travel-orders.show', ['sptGroupId' => $sptGroupId] + OfficerSptNavigation::context($request))
            ->with('success', 'SPT sudah dibagikan dan sekarang tersedia untuk Pegawai.');
    }

    private function workflow(string $sptGroupId): SptSrikandiWorkflow
    {
        return SptSrikandiWorkflow::query()
            ->with([
                'travels' => fn($query) => $query->with('pegawai')->orderBy('id'),
                'versions.preparer',
                'versions.submitter',
                'versions.revisionRequester',
            ])
            ->where('spt_group_id', $sptGroupId)
            ->firstOrFail();
    }

    private function conceptResponse(
        SptSrikandiWorkflow $workflow,
        SptSrikandiVersion $version,
        string $path
    ): BinaryFileResponse {
        $reference = $workflow->travels->first()?->spt_internal_reference ?: 'SPT';
        $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $reference) ?: 'SPT';

        return response()->download(
            $path,
            'Konsep_SPT_'.$safeReference.'_V'.$version->version_number.'.docx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'X-SPT-Concept-Version' => (string) $version->version_number,
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
