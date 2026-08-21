<?php

namespace App\Http\Controllers;

use App\Models\PerjalananDinas;
use App\Services\TravelStatusTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RealizationController extends Controller
{
    public function __construct(private readonly TravelStatusTransition $transition) {}

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

        return view('travel.realization', compact('travel'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'biaya_hotel' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'biaya_tiket' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ]);

        $precheck = PerjalananDinas::query()
            ->with('laporan')
            ->whereKey((int) $data['id'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_REJECTED,
            ])
            ->firstOrFail();
        if (! $precheck->laporan) {
            return redirect()
                ->route('travel-reports.edit', ['travel' => $precheck->id])
                ->withErrors([
                    'laporan' => 'Laporan Perjalanan Dinas wajib diisi sebelum realisasi biaya dikirim.',
                ]);
        }

        DB::transaction(function () use ($request, $data): void {
            $travel = PerjalananDinas::query()
                ->with('laporan')
                ->whereKey((int) $data['id'])
                ->where('user_id', $request->user()->id)
                ->whereIn('status', [
                    PerjalananDinas::STATUS_READY,
                    PerjalananDinas::STATUS_REJECTED,
                ])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $travel->laporan) {
                throw ValidationException::withMessages([
                    'laporan' => 'Laporan Perjalanan Dinas wajib diisi sebelum realisasi biaya dikirim.',
                ]);
            }

            $wasRejected = $travel->status === PerjalananDinas::STATUS_REJECTED;
            $this->transition->apply(
                $travel,
                PerjalananDinas::STATUS_PENDING,
                $request->user(),
                [
                    'biaya_hotel_real' => $data['biaya_hotel'],
                    'biaya_tiket_real' => $data['biaya_tiket'],
                    'biaya_hotel_approved' => 0,
                    'biaya_tiket_approved' => 0,
                    'total_cair' => 0,
                    'tgl_lapor' => now()->toDateString(),
                    'verified_by' => null,
                    'verified_at' => null,
                ],
                $wasRejected
                    ? 'Realisasi diperbaiki dan dikirim ulang oleh pegawai.'
                    : 'Realisasi dikirim oleh pegawai.'
            );
        });

        return redirect()
            ->route('dashboard.user')
            ->with('success', 'Laporan realisasi berhasil dikirim.');
    }

    private function ownedEditableTravel(Request $request): PerjalananDinas
    {
        return PerjalananDinas::query()
            ->with(['laporan', 'statusHistories.actor'])
            ->whereKey($request->integer('id'))
            ->where('user_id', $request->user()->id)
            ->whereIn('status', [
                PerjalananDinas::STATUS_READY,
                PerjalananDinas::STATUS_REJECTED,
            ])
            ->firstOrFail();
    }
}
