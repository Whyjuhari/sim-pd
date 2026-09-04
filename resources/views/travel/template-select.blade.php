@extends('layouts.app')
@section('title', 'Pilih Template SPT - SIM-PD')
@section('page-title', 'Buat Surat Perintah Tugas')
@section('page-subtitle', 'Pilih template kop dan layout cetak SPT terlebih dahulu')
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-11">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-file-earmark-text-fill me-2"></i>Pilih Template SPT</h5>
                        <a href="{{ route('dashboard.officer') }}" class="btn btn-outline-secondary btn-sm"><i
                                class="bi bi-arrow-left me-1"></i>Kembali</a>
                    </div>
                    <div class="row g-4">
                        @forelse ($templates as $template)
                            <div class="col-sm-6 col-lg-4 col-xxl-3">
                                <a href="{{ route('travel-orders.create', ['template' => $template->id]) }}"
                                    class="text-decoration-none template-choice h-100 d-block">
                                    <div class="card h-100 border shadow-sm">
                                        <div class="spt-thumb-frame is-paper">
                                            <div class="spt-thumb-wrap">
                                                @if ($template->existsThumbnail())
                                                    <canvas
                                                        data-spt-template-thumb="{{ route('spt-templates.thumbnail', $template) }}"
                                                        aria-label="Pratinjau {{ $template->nama }}"></canvas>
                                                    <div class="spt-thumb-fallback d-none">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                        <small>Pratinjau tidak tersedia</small>
                                                    </div>
                                                @else
                                                    <div class="spt-thumb-fallback">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                        <small>Pratinjau belum tersedia</small>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="spt-thumb-label">
                                                {{ $template->is_default ? 'Default' : 'Template' }}
                                            </div>
                                        </div>
                                        <div class="card-body d-flex flex-column pt-3">
                                            <div class="d-flex align-items-start justify-content-between gap-2">
                                                <h6 class="fw-bold mb-1 text-dark">{{ $template->nama }}</h6>
                                                @if ($template->is_default)
                                                    <span class="badge text-bg-primary text-nowrap">Default</span>
                                                @endif
                                            </div>
                                            @if ($template->deskripsi)
                                                <p class="small text-muted mb-2">{{ $template->deskripsi }}</p>
                                            @else
                                                <p class="small text-muted mb-2">Template Surat Perintah Tugas.</p>
                                            @endif
                                            <div class="mt-auto small text-secondary">
                                                <i class="bi bi-hdd me-1"></i>{{ $template->humanReadableSize() }} ·
                                                Dipakai {{ $template->perjalanan_dinas_count ?? 0 }} SPT
                                            </div>
                                            <span class="btn btn-identity btn-sm mt-3 w-100">Gunakan Template</span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @empty
                            <div class="col-12">
                                <div class="alert alert-warning mb-3">
                                    Belum ada template yang diunggah. Buat SPT dengan template sistem bawaan,
                                    atau unggah template.
                                </div>
                            </div>
                        @endforelse

                        <div class="col-sm-6 col-lg-4 col-xxl-3">
                            <a href="{{ route('travel-orders.create', ['template' => 'system']) }}"
                                class="text-decoration-none template-choice h-100 d-block">
                                <div class="card h-100 border shadow-sm">
                                    <div class="spt-thumb-frame ml-2">
                                        <div class="spt-thumb-fallback">
                                            <i class="bi bi-file-earmark-fill"></i>
                                            <small>Layout bawaan aplikasi</small>
                                        </div>
                                    </div>
                                    <div class="card-body d-flex flex-column">
                                        <h6 class="fw-bold mb-1 text-dark">Template Sistem</h6>
                                        <p class="small text-muted mb-2">Menggunakan kop dan layout bawaan
                                            aplikasi.</p>
                                        <div class="mt-auto small text-secondary"><i class="bi bi-hdd me-1"></i>Default
                                            aplikasi</div>
                                        <span class="btn btn-outline-secondary btn-sm mt-3 w-100">Gunakan Template</span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
