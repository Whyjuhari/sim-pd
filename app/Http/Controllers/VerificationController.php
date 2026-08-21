<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Services\TravelCostCalculator;
use App\Services\TravelStatusTransition;
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
        private readonly TravelStatusTransition $transition
    ) {}

    public function show(Request $request): View
    {
        $travel = $this->pendingTravel($request)->load(['pegawai', 'laporan', 'statusHistories.actor']);
        $recommendation = $this->calculator->verificationRecommendation($travel->getAttributes());

        return view('travel.verification', compact('travel', 'recommendation'));
    }

    public function store(Request $request): RedirectResponse
    {
        $action = (string) $request->input('action', 'approve');
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'action' => ['nullable', Rule::in(['approve', 'reject'])],
            'hotel_approved' => [
                Rule::requiredIf($action === 'approve'),
                'nullable', 'numeric', 'min:0', 'max:9999999999999.99',
            ],
            'tiket_approved' => [
                Rule::requiredIf($action === 'approve'),
                'nullable', 'numeric', 'min:0', 'max:9999999999999.99',
            ],
            'catatan' => [
                Rule::requiredIf($action === 'reject'),
                'nullable', 'string', 'max:5000',
            ],
        ]);

        DB::transaction(function () use ($request, $data, $action): void {
            $travel = PerjalananDinas::query()
                ->whereKey((int) $data['id'])
                ->where('status', PerjalananDinas::STATUS_PENDING)
                ->lockForUpdate()
                ->firstOrFail();

            if ($action === 'reject') {
                $this->transition->apply(
                    $travel,
                    PerjalananDinas::STATUS_REJECTED,
                    $request->user(),
                    [
                        'biaya_hotel_approved' => 0,
                        'biaya_tiket_approved' => 0,
                        'total_cair' => 0,
                        'catatan_verifikator' => $data['catatan'],
                        'verified_by' => $request->user()->id,
                        'verified_at' => now(),
                    ],
                    $data['catatan']
                );

                return;
            }

            $recommendation = $this->calculator->verificationRecommendation($travel->getAttributes());
            $hotelApproved = (float) $data['hotel_approved'];
            $ticketApproved = (float) $data['tiket_approved'];

            if ($hotelApproved > $recommendation['hotel_limit']) {
                throw ValidationException::withMessages([
                    'hotel_approved' => 'Nilai hotel yang disetujui melebihi batas total perjalanan.',
                ]);
            }
            if ($ticketApproved > $recommendation['transport_limit']) {
                throw ValidationException::withMessages([
                    'tiket_approved' => 'Nilai transportasi yang disetujui melebihi batas angkutan.',
                ]);
            }

            $total = $hotelApproved + $ticketApproved + $recommendation['daily_allowance_total'];
            $this->transition->apply(
                $travel,
                PerjalananDinas::STATUS_APPROVED,
                $request->user(),
                [
                    'biaya_hotel_approved' => $hotelApproved,
                    'biaya_tiket_approved' => $ticketApproved,
                    'total_cair' => $total,
                    'catatan_verifikator' => $data['catatan'] ?? null,
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ],
                $data['catatan'] ?? 'Realisasi disetujui.'
            );
        });

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
