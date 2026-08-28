<?php

namespace App\Http\Controllers;

use App\Models\BuktiRealisasi;
use App\Services\Uploads\RealizationEvidenceStorage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RealizationEvidenceController extends Controller
{
    public function show(
        BuktiRealisasi $evidence,
        RealizationEvidenceStorage $storage
    ): BinaryFileResponse {
        Gate::authorize('view', $evidence);

        $path = $storage->absolutePath($evidence->path);
        abort_if($path === null, 404);

        $headers = [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $evidence->mime_type,
        ];

        if ($evidence->isImage()) {
            return response()->file($path, $headers);
        }

        return response()->download($path, $evidence->nama_asli, $headers, 'attachment');
    }
}
