<?php

namespace App\Http\Controllers;

use App\Models\BuktiRealisasi;
use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use App\Services\RealizationDetailService;
use App\Services\TravelCostCalculator;
use App\Services\TravelStatusTransition;
use App\Services\Uploads\RealizationEvidenceStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RealizationController extends Controller
{
    public function __construct(
        private readonly TravelStatusTransition $transition,
        private readonly RealizationDetailService $details,
        private readonly TravelCostCalculator $calculator
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $travel = $this->ownedEditableTravel($request);

        if (! $travel->laporan) {
            return redirect()
                ->route('travel-reports.edit', ['travel' => $travel->id])
                ->withErrors([
                    'laporan' => 'Silakan lengkapi Laporan Perjalanan Dinas sebelum mengisi realisasi biaya.',
                ]);
        }

        $recommendation = $this->calculator->verificationRecommendation($travel->getAttributes());
        $items = $this->formItems($request, $travel);

        return view('travel.realization', compact('travel', 'items', 'recommendation'));
    }

    public function store(Request $request, RealizationEvidenceStorage $evidenceStorage): RedirectResponse
    {
        $travelId = (int) $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ])['id'];
        $precheck = $this->editableTravelQuery($request, $travelId)->firstOrFail();
        if (! $precheck->laporan) {
            return redirect()
                ->route('travel-reports.edit', ['travel' => $precheck->id])
                ->withErrors(['laporan' => 'Laporan Perjalanan Dinas wajib diisi sebelum realisasi biaya dikirim.']);
        }

        $maxFiles = max(1, (int) config(
            'sim_pd.realization_evidence.max_files_per_detail',
            config('sim_pd.realization_evidence.max_files_per_type', 3)
        ));
        $maxKilobytes = max(1, (int) config('sim_pd.realization_evidence.max_kilobytes_per_file', 5120));
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.code' => ['nullable', 'string', 'max:80'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.overrun_reason' => ['nullable', 'string', 'max:2000'],
            'items.*.office_route_confirmed' => ['nullable', 'boolean'],
            'items.*.non_private_vehicle_confirmed' => ['nullable', 'boolean'],
            'items.*.evidence' => ['nullable', 'array', 'max:'.$maxFiles],
            'items.*.evidence.*' => [
                'required', 'file', 'mimes:jpg,jpeg,png,pdf',
                'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.$maxKilobytes,
            ],
            'hapus_bukti' => ['nullable', 'array'],
            'hapus_bukti.*' => ['integer', 'distinct', 'min:1'],
            'hapus_rincian' => ['nullable', 'array'],
            'hapus_rincian.*' => ['integer', 'distinct', 'min:1'],
        ], [
            'items.required' => 'Minimal satu rincian realisasi wajib tersedia.',
            'items.*.evidence.max' => 'Setiap rincian maksimal memiliki tiga bukti.',
            'items.*.evidence.*.mimes' => 'Bukti hanya boleh berupa JPG, JPEG, PNG, atau PDF.',
            'items.*.evidence.*.max' => 'Ukuran setiap bukti maksimal 5 MB.',
        ]);

        $deleteEvidenceIds = $this->integerIds($data['hapus_bukti'] ?? []);
        $deleteDetailIds = $this->integerIds($data['hapus_rincian'] ?? []);
        $normalized = $this->withAirBenchmarks(
            $precheck,
            $this->normalizeItems($precheck, $data['items'], $deleteDetailIds),
            $data['items']
        );
        $this->ensureEvidenceRequirements(
            $precheck,
            $normalized,
            $data['items'],
            $deleteEvidenceIds,
            $maxFiles
        );

        $stored = [];
        $deletedPaths = [];

        try {
            foreach ($normalized as $item) {
                foreach (($data['items'][$item['request_key']]['evidence'] ?? []) as $file) {
                    $stored[$item['request_key']][] = $evidenceStorage->store(
                        $file,
                        "items.{$item['request_key']}.evidence"
                    );
                }
            }

            $deletedPaths = DB::transaction(function () use (
                $request,
                $data,
                $deleteEvidenceIds,
                $deleteDetailIds,
                $stored,
                $maxFiles
            ): array {
                $travel = $this->editableTravelQuery($request, (int) $data['id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $travel->laporan) {
                    throw ValidationException::withMessages([
                        'laporan' => 'Laporan Perjalanan Dinas wajib diisi sebelum realisasi biaya dikirim.',
                    ]);
                }

                $normalized = $this->withAirBenchmarks(
                    $travel,
                    $this->normalizeItems($travel, $data['items'], $deleteDetailIds),
                    $data['items']
                );
                $this->ensureEvidenceRequirements(
                    $travel,
                    $normalized,
                    $data['items'],
                    $deleteEvidenceIds,
                    $maxFiles
                );

                $paths = $this->pathsScheduledForDeletion(
                    $travel,
                    $deleteEvidenceIds,
                    $deleteDetailIds
                );

                if ($deleteEvidenceIds !== []) {
                    BuktiRealisasi::query()
                        ->where('perjalanan_dinas_id', $travel->id)
                        ->whereIn('id', $deleteEvidenceIds)
                        ->delete();
                }
                if ($deleteDetailIds !== []) {
                    RealisasiRincian::query()
                        ->where('perjalanan_dinas_id', $travel->id)
                        ->whereIn('id', $deleteDetailIds)
                        ->where('is_preset', false)
                        ->delete();
                }

                foreach ($normalized as $item) {
                    $detail = $item['id']
                        ? RealisasiRincian::query()
                            ->where('perjalanan_dinas_id', $travel->id)
                            ->findOrFail($item['id'])
                        : new RealisasiRincian(['perjalanan_dinas_id' => $travel->id]);

                    $detail->fill([
                        'kategori' => $item['category'],
                        'kode' => $item['code'],
                        'uraian' => $item['description'],
                        'nilai_diajukan' => $item['amount'],
                        'nilai_disetujui' => null,
                        'benchmark_source' => $item['benchmark_source'],
                        'benchmark_amount_snapshot' => $item['benchmark_amount'],
                        'overrun_reason' => $item['overrun_reason'],
                        'office_route_confirmed' => $item['office_route_confirmed'],
                        'non_private_vehicle_confirmed' => $item['non_private_vehicle_confirmed'],
                        'is_preset' => $item['is_preset'],
                        'urutan' => $item['order'],
                    ]);
                    $detail->save();

                    foreach ($stored[$item['request_key']] ?? [] as $metadata) {
                        $nextOrder = (int) $detail->bukti()->max('urutan') + 1;
                        $detail->bukti()->create([
                            ...$metadata,
                            'perjalanan_dinas_id' => $travel->id,
                            'jenis' => $item['category'],
                            'urutan' => $nextOrder,
                        ]);
                    }
                }

                RealisasiRincian::query()
                    ->where('perjalanan_dinas_id', $travel->id)
                    ->update(['nilai_disetujui' => null]);
                $this->details->syncAggregates($travel);

                $wasRejected = $travel->status === PerjalananDinas::STATUS_REJECTED;
                $this->transition->apply(
                    $travel->fresh(),
                    PerjalananDinas::STATUS_PENDING,
                    $request->user(),
                    [
                        'total_cair' => 0,
                        'tgl_lapor' => now()->toDateString(),
                        'verified_by' => null,
                        'verified_at' => null,
                    ],
                    $wasRejected
                        ? 'Realisasi diperbaiki dan dikirim ulang oleh pegawai.'
                        : 'Realisasi dikirim oleh pegawai.'
                );

                return $paths;
            });
        } catch (Throwable $exception) {
            $evidenceStorage->deleteMany(
                collect($stored)->flatten(1)->pluck('path')->all()
            );
            throw $exception;
        }

        $evidenceStorage->deleteMany($deletedPaths);

        return redirect()
            ->route('dashboard.user')
            ->with('success', 'Laporan realisasi berhasil dikirim.');
    }

    private function ownedEditableTravel(Request $request): PerjalananDinas
    {
        return $this->editableTravelQuery($request, $request->integer('id'))->firstOrFail();
    }

    private function editableTravelQuery(Request $request, int $id)
    {
        return PerjalananDinas::query()
            ->with([
                'laporan', 'statusHistories.actor',
                'rincianRealisasi.bukti', 'buktiRealisasi',
            ])
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_REJECTED,
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeItems(PerjalananDinas $travel, array $input, array $deleteDetailIds): array
    {
        $existing = $travel->rincianRealisasi->keyBy('id');
        $hasExisting = $existing->isNotEmpty();
        $presetByCode = collect($this->details->presets($travel))->keyBy('code');
        $seenIds = [];
        $seenCodes = [];
        $customCount = 0;
        $normalized = [];

        foreach ($input as $requestKey => $item) {
            if (! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', (string) $requestKey)) {
                throw ValidationException::withMessages(['items' => 'Identitas rincian realisasi tidak valid.']);
            }
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id && in_array($id, $deleteDetailIds, true)) {
                continue;
            }

            if ($id) {
                $detail = $existing->get($id);
                if (! $detail || in_array($id, $seenIds, true)) {
                    throw ValidationException::withMessages([
                        "items.{$requestKey}.id" => 'Rincian realisasi tidak valid.',
                    ]);
                }
                $seenIds[] = $id;
                $description = $detail->is_preset
                    ? $detail->uraian
                    : trim((string) ($item['description'] ?? ''));
                if (! $detail->is_preset && ($description === '' || (float) $item['amount'] <= 0)) {
                    throw ValidationException::withMessages([
                        "items.{$requestKey}.description" => 'Transportasi lainnya memerlukan uraian dan nominal lebih dari nol.',
                    ]);
                }
                $normalized[] = [
                    'request_key' => (string) $requestKey,
                    'id' => $id,
                    'category' => $detail->kategori,
                    'code' => $detail->kode,
                    'description' => $description,
                    'amount' => (float) $item['amount'],
                    'is_preset' => (bool) $detail->is_preset,
                    'order' => (int) $detail->urutan,
                ];
                continue;
            }

            $code = trim((string) ($item['code'] ?? ''));
            if (! $hasExisting && $code !== '' && $presetByCode->has($code)) {
                if (in_array($code, $seenCodes, true)) {
                    throw ValidationException::withMessages([
                        "items.{$requestKey}.code" => 'Rincian preset tidak boleh digandakan.',
                    ]);
                }
                $preset = $presetByCode->get($code);
                $seenCodes[] = $code;
                $normalized[] = [
                    'request_key' => (string) $requestKey,
                    'id' => null,
                    'category' => $preset['category'],
                    'code' => $preset['code'],
                    'description' => $preset['description'],
                    'amount' => (float) $item['amount'],
                    'is_preset' => true,
                    'order' => $preset['order'],
                ];
                continue;
            }

            if ($code !== '') {
                throw ValidationException::withMessages([
                    "items.{$requestKey}.code" => 'Kode rincian realisasi tidak valid.',
                ]);
            }

            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '' || (float) $item['amount'] <= 0) {
                throw ValidationException::withMessages([
                    "items.{$requestKey}.description" => 'Transportasi lainnya memerlukan uraian dan nominal lebih dari nol.',
                ]);
            }
            $customCount++;
            $normalized[] = [
                'request_key' => (string) $requestKey,
                'id' => null,
                'category' => RealisasiRincian::CATEGORY_TRANSPORT,
                'code' => null,
                'description' => $description,
                'amount' => (float) $item['amount'],
                'is_preset' => false,
                'order' => 100 + $customCount,
            ];
        }

        if ($customCount > 10) {
            throw ValidationException::withMessages(['items' => 'Maksimal sepuluh transportasi tambahan dapat diisi.']);
        }

        if ($hasExisting) {
            $requiredIds = $existing->keys()
                ->map(fn ($id): int => (int) $id)
                ->diff($deleteDetailIds)
                ->all();
            if (array_diff($requiredIds, $seenIds) !== []) {
                throw ValidationException::withMessages(['items' => 'Rincian tersimpan tidak boleh dihilangkan tanpa proses hapus yang sah.']);
            }
        } elseif (array_diff($presetByCode->keys()->all(), $seenCodes) !== []) {
            throw ValidationException::withMessages(['items' => 'Seluruh rincian wajib dari SPT harus tersedia.']);
        }

        foreach ($deleteDetailIds as $deleteId) {
            $detail = $existing->get($deleteId);
            if (! $detail || $detail->is_preset) {
                throw ValidationException::withMessages(['hapus_rincian' => 'Hanya transportasi tambahan yang dapat dihapus.']);
            }
        }

        return $normalized;
    }

    private function ensureEvidenceRequirements(
        PerjalananDinas $travel,
        array $normalized,
        array $input,
        array $deleteEvidenceIds,
        int $maxFiles
    ): void {
        $ownedEvidence = $travel->buktiRealisasi->keyBy('id');
        if (array_diff($deleteEvidenceIds, $ownedEvidence->keys()->map(fn ($id): int => (int) $id)->all()) !== []) {
            throw ValidationException::withMessages(['hapus_bukti' => 'Bukti yang akan dihapus tidak valid untuk perjalanan ini.']);
        }

        $errors = [];
        foreach ($normalized as $item) {
            $existingCount = $item['id']
                ? $travel->buktiRealisasi
                    ->where('realisasi_rincian_id', $item['id'])
                    ->whereNotIn('id', $deleteEvidenceIds)
                    ->count()
                : 0;
            $newCount = count($input[$item['request_key']]['evidence'] ?? []);
            $count = $existingCount + $newCount;
            $field = "items.{$item['request_key']}.evidence";

            if ($count > $maxFiles) {
                $errors[$field] = 'Setiap rincian maksimal memiliki tiga bukti.';
            } elseif ($item['amount'] > 0 && $count < 1) {
                $errors[$field] = 'Minimal satu bukti wajib diunggah jika nominal rincian lebih dari nol.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function withAirBenchmarks(PerjalananDinas $travel, array $normalized, array $input): array
    {
        foreach ($normalized as &$item) {
            $benchmark = $this->details->benchmarkFor($travel, $item['code']);
            $submitted = $input[$item['request_key']] ?? [];
            $reason = trim((string) ($submitted['overrun_reason'] ?? ''));
            $officeRoute = filter_var($submitted['office_route_confirmed'] ?? false, FILTER_VALIDATE_BOOL);
            $nonPrivate = filter_var($submitted['non_private_vehicle_confirmed'] ?? false, FILTER_VALIDATE_BOOL);
            $hasBenchmark = $benchmark['amount'] !== null;
            $exceeds = $hasBenchmark && $item['amount'] > (float) $benchmark['amount'];

            if ($travel->transport_rate_source === 'pmk_air' && $exceeds && $reason === '') {
                throw ValidationException::withMessages([
                    "items.{$item['request_key']}.overrun_reason" => 'Alasan wajib diisi karena nilai aktual melebihi patokan.',
                ]);
            }
            if ($travel->transport_rate_source === 'pmk_air' && $exceeds && $benchmark['terminal'] && $benchmark['source'] === 'pmk') {
                if (! $officeRoute || ! $nonPrivate) {
                    throw ValidationException::withMessages([
                        "items.{$item['request_key']}.office_route_confirmed" => 'Kedua pernyataan perjalanan terminal wajib dikonfirmasi ketika nilai melampaui patokan PMK.',
                    ]);
                }
            }

            $item['benchmark_source'] = $benchmark['source'];
            $item['benchmark_amount'] = $benchmark['amount'];
            $item['overrun_reason'] = $exceeds ? $reason : null;
            $item['office_route_confirmed'] = $exceeds && $benchmark['terminal'] && $officeRoute;
            $item['non_private_vehicle_confirmed'] = $exceeds && $benchmark['terminal'] && $nonPrivate;
        }
        unset($item);

        return $normalized;
    }

    /** @return array<int, string> */
    private function pathsScheduledForDeletion(
        PerjalananDinas $travel,
        array $deleteEvidenceIds,
        array $deleteDetailIds
    ): array {
        return $travel->buktiRealisasi
            ->filter(fn (BuktiRealisasi $evidence): bool =>
                in_array((int) $evidence->id, $deleteEvidenceIds, true)
                || in_array((int) $evidence->realisasi_rincian_id, $deleteDetailIds, true)
            )
            ->pluck('path')
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function formItems(Request $request, PerjalananDinas $travel): array
    {
        $items = $travel->rincianRealisasi->isNotEmpty()
            ? $travel->rincianRealisasi->map(fn (RealisasiRincian $detail): array => [
                'key' => 'detail_'.$detail->id,
                'id' => $detail->id,
                'category' => $detail->kategori,
                'code' => $detail->kode,
                'description' => $detail->uraian,
                'amount' => (float) $detail->nilai_diajukan,
                'is_preset' => (bool) $detail->is_preset,
                'order' => (int) $detail->urutan,
                'evidence' => $detail->bukti,
                'benchmark_source' => $detail->benchmark_source,
                'benchmark_amount' => $detail->benchmark_amount_snapshot === null ? null : (float) $detail->benchmark_amount_snapshot,
                'overrun_reason' => $detail->overrun_reason,
                'office_route_confirmed' => (bool) $detail->office_route_confirmed,
                'non_private_vehicle_confirmed' => (bool) $detail->non_private_vehicle_confirmed,
            ])->all()
            : collect($this->details->presets($travel))->map(fn (array $preset): array => [
                ...$preset,
                'id' => null,
                'amount' => 0,
                'is_preset' => true,
                'evidence' => collect(),
                'benchmark_source' => $this->details->benchmarkFor($travel, $preset['code'])['source'],
                'benchmark_amount' => $this->details->benchmarkFor($travel, $preset['code'])['amount'],
                'overrun_reason' => null,
                'office_route_confirmed' => false,
                'non_private_vehicle_confirmed' => false,
            ])->all();

        $oldItems = $request->old('items');
        if (! is_array($oldItems)) {
            return $items;
        }

        $baseById = collect($items)->filter(fn (array $item): bool => (bool) $item['id'])->keyBy('id');
        $baseByCode = collect($items)->filter(fn (array $item): bool => (bool) $item['code'])->keyBy('code');

        return collect($oldItems)->map(function (array $old, string $key) use ($baseById, $baseByCode): array {
            $base = isset($old['id'])
                ? $baseById->get((int) $old['id'])
                : $baseByCode->get((string) ($old['code'] ?? ''));

            return [
                'key' => $key,
                'id' => $base['id'] ?? null,
                'category' => $base['category'] ?? RealisasiRincian::CATEGORY_TRANSPORT,
                'code' => $base['code'] ?? null,
                'description' => (string) ($old['description'] ?? $base['description'] ?? ''),
                'amount' => (float) ($old['amount'] ?? $base['amount'] ?? 0),
                'is_preset' => (bool) ($base['is_preset'] ?? false),
                'order' => (int) ($base['order'] ?? 100),
                'evidence' => $base['evidence'] ?? collect(),
                'benchmark_source' => $base['benchmark_source'] ?? null,
                'benchmark_amount' => $base['benchmark_amount'] ?? null,
                'overrun_reason' => (string) ($old['overrun_reason'] ?? $base['overrun_reason'] ?? ''),
                'office_route_confirmed' => (bool) ($old['office_route_confirmed'] ?? $base['office_route_confirmed'] ?? false),
                'non_private_vehicle_confirmed' => (bool) ($old['non_private_vehicle_confirmed'] ?? $base['non_private_vehicle_confirmed'] ?? false),
            ];
        })->values()->all();
    }

    /** @return array<int, int> */
    private function integerIds(array $values): array
    {
        return array_values(array_unique(array_map('intval', $values)));
    }
}
