@extends('layouts.app')
@section('title', 'Kesehatan Sistem - SIM-PD')
@section('brand', 'Kesehatan Sistem')

@section('content')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h4 class="mb-1">Pemeriksaan Kesiapan Sistem</h4>
            <p class="text-muted mb-0">Pemeriksaan aman tanpa menampilkan path atau detail internal server.</p>
        </div>
        <span class="badge fs-6 {{ $allHealthy ? 'bg-success' : 'bg-warning text-dark' }}">
            {{ $allHealthy ? 'Semua siap' : 'Perlu perhatian' }}
        </span>
    </div>
    <div class="card shadow-sm">
        <div class="list-group list-group-flush">
            @foreach($checks as $check)
                <div class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div><div class="fw-semibold">{{ $check['label'] }}</div><small class="text-muted">{{ $check['message'] }}</small></div>
                    <i class="bi {{ $check['ok'] ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning' }} fs-4" aria-label="{{ $check['ok'] ? 'Siap' : 'Bermasalah' }}"></i>
                </div>
            @endforeach
        </div>
    </div>
@endsection
