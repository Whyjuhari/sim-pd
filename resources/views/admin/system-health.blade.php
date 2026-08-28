@extends('layouts.app')
@section('title', 'Kesehatan Sistem - SIM-PD')
@section('brand', 'Kesehatan Sistem')
@section('page-subtitle', 'Periksa koneksi database, penyimpanan privat, template, dan layanan dokumen produksi.')
@section('page-actions')
    <span class="badge rounded-pill fs-6 px-3 py-2 {{ $allHealthy ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
        <i class="bi {{ $allHealthy ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' }}"></i>
        {{ $allHealthy ? 'Semua siap' : 'Perlu perhatian' }}
    </span>
@endsection

@section('content')
    <div class="card shadow-sm">
        <div class="card-header bg-white"><h2 class="section-title"><span class="section-title-icon"><i class="bi bi-shield-check"></i></span> Pemeriksaan Kesiapan Sistem</h2></div>
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
