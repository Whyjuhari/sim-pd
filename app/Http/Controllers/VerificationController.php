<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Models\RealisasiRincian;
use App\Services\RealizationDetailService;
use App\Services\TravelCostCalculator;
use App\Services\TravelStatusTransition;
use App\Services\Reports\PmkComplianceService;
use App\Services\Documents\DocumentCachePrewarmer;
use App\Services\Documents\TravelPdfDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VerificationController extends Controller
{
    public function __construct(
        private readonly TravelCostCalculator $calculator,
        private readonly TravelStatusTransition $transition,
        private readonly RealizationDetailService $details,
        private readonly PmkComplianceService $compliance,
        private readonly DocumentCachePrewarmer $documentPrewarmer
    ) {}

    public function show(Request $request): View
    {
        $travel = $this->pendingTravel($request)->load([
            'pegawai', 'laporan', 'statusHistories.actor',
            'rincianRealisasi.bukti', 'buktiRealisasi',
        ]);
        $recommendation = $this->calculator->verificationRecommendation($travel->getAttributes());
        $recommendedApprovals = $this->details->recommendedApprovals(
            $travel->rincianRealisasi,
            $recommendation
        );
        $exceptionSummary = $this->compliance->forTravel($travel);

        return view('travel.verification', compact(
            'travel',
            'recommendation',
            'recommendedApprovals',
            'exceptionSummary'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $action = (string) $request->input('action', 'approve');
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'action' => ['nullable', Rule::in(['approve', 'reject'])],
            'catatan' => [Rule::requiredIf($action === 'reject'), 'nullable', 'string', 'max:5000'],
        ]);

        $approvedTravelId = null;

        DB::transaction(function () use ($request, $data, $action, &$approvedTravelId): void {
            $travel = PerjalananDinas::query()
                ->with('rincianRealisasi')
                ->whereKey((int) $data['id'])
                ->where('status', PerjalananDinas::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            $details = RealisasiRincian::query()
                ->where('perjalanan_dinas_id', $travel->id)
                ->orderBy('urutan')
                ->lockForUpdate()
                ->get();

            if ($action === 'reject') {
                RealisasiRincian::query()
                    ->where('perjalanan_dinas_id', $travel->id)
                    ->update(['nilai_disetujui' => null]);
                $this->details->syncAggregates($travel);
                $this->transition->apply(
                    $travel->fresh(),
                    PerjalananDinas::STATUS_REJECTED,
                    $request->user(),
                    [
                        'total_cair' => 0,
                        'catatan_verifikator' => $data['catatan'],
                        'verified_by' => $request->user()->id,
                        'verified_at' => now(),
                    ],
                    $data['catatan']
                );

                return;
            }

            if ($details->isEmpty()) {
                throw ValidationException::withMessages([
                    'approved' => 'Rincian realisasi belum tersedia dan tidak dapat disetujui.',
                ]);
            }

            $recommendation = $this->calculator->verificationRecommendation($travel->getAttributes());
            $approvedValues = $this->details->recommendedApprovals($details, $recommendation);
            $categoryTotals = [
                RealisasiRincian::CATEGORY_HOTEL => 0.0,
                RealisasiRincian::CATEGORY_TRANSPORT => 0.0,
            ];

            foreach ($details as $detail) {
                $approved = (float) ($approvedValues[(int) $detail->id] ?? 0);
                $categoryTotals[$detail->kategori] += $approved;
                $detail->update(['nilai_disetujui' => $approved]);
            }

            if ($categoryTotals[RealisasiRincian::CATEGORY_HOTEL] > (float) $recommendation['hotel_limit']) {
                throw ValidationException::withMessages([
                    'approved' => 'Total hotel yang disetujui melebihi batas perjalanan.',
                ]);
            }
            if (
                ! in_array(($recommendation['transport_rate_source'] ?? 'legacy'), ['pmk_ground', 'pmk_air'], true)
                && $categoryTotals[RealisasiRincian::CATEGORY_TRANSPORT] > (float) $recommendation['transport_limit']
            ) {
                throw ValidationException::withMessages([
                    'approved' => 'Total transportasi yang disetujui melebihi batas angkutan.',
                ]);
            }

            $this->details->syncAggregates($travel);
            $total = $categoryTotals[RealisasiRincian::CATEGORY_HOTEL]
                + $categoryTotals[RealisasiRincian::CATEGORY_TRANSPORT]
                + (float) $recommendation['daily_allowance_total'];

            $this->transition->apply(
                $travel->fresh(),
                PerjalananDinas::STATUS_APPROVED,
                $request->user(),
                [
                    'total_cair' => $total,
                    'catatan_verifikator' => $data['catatan'] ?? null,
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ],
                $data['catatan'] ?? 'Realisasi disetujui.'
            );

            $approvedTravelId = (int) $travel->id;
        });

        if ($approvedTravelId) {
            $this->documentPrewarmer->afterResponse(
                TravelPdfDocumentService::TYPE_TRAVEL,
                $approvedTravelId
            );
        }

        return redirect()
            ->route('dashboard.verifier')
            ->with('success', $action === 'reject'
                ? 'Realisasi dikembalikan kepada pegawai untuk diperbaiki.'
                : 'Pengajuan berhasil disetujui dan dicairkan.');
    }

    private function pendingTravel(Request $request): PerjalananDinas
    {
        return PerjalananDinas::query()
            ->whereKey($request->integer('id'))
            ->where('status', PerjalananDinas::STATUS_PENDING)
            ->firstOrFail();
    }
}
