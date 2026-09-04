<?php

namespace App\Services\Documents;

use App\Models\PerjalananDinas;
use Throwable;

class DocumentCachePrewarmer
{
    public function __construct(private readonly TravelPdfDocumentService $documents) {}

    public function afterResponse(string $documentType, int $travelId): void
    {
        if (! config('sim_pd.documents.cache.prewarm_after_response', true)) {
            return;
        }

        app()->terminating(function () use ($documentType, $travelId): void {
            try {
                $this->warm($documentType, $travelId);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function warm(string $documentType, int $travelId): void
    {
        $travel = PerjalananDinas::query()->find($travelId);
        if (! $travel) {
            return;
        }

        switch ($documentType) {
            case TravelPdfDocumentService::TYPE_SPT:
                $this->documents->suratTugas($travel);
                break;
            case TravelPdfDocumentService::TYPE_REPORT:
                $this->warmReport($travel);
                break;
            case TravelPdfDocumentService::TYPE_TRAVEL:
                $this->warmTravel($travel);
                break;
        }
    }

    private function warmReport(PerjalananDinas $travel): void
    {
        $travel->loadMissing(['pegawai', 'laporan.dokumentasi']);

        if ($travel->laporan && $travel->pegawai?->signatureAbsolutePath()) {
            $this->documents->laporanPerjadin($travel);
        }
    }

    private function warmTravel(PerjalananDinas $travel): void
    {
        if ($travel->status === PerjalananDinas::STATUS_APPROVED) {
            $this->documents->perjadin($travel);
        }
    }
}
