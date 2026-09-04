@extends('layouts.app')
@section('title', 'Template SPT - SIM-PD')
@section('brand', 'Template SPT')
@section('page-subtitle', 'Kelola template DOCX yang digunakan untuk mencetak Surat Perintah Tugas.')
@section('page-actions')
    <a href="{{ route('dashboard.officer') }}" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left"></i> Kembali ke SPT</a>
@endsection

@section('content')
    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i>
        Template yang dipilih pada saat SPT dibuat akan <strong>terkunci</strong> dan digunakan
        untuk mencetak SPT tersebut.
    </div>

    <div class="row g-3 mb-4 mt-1">
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold text-identity">
                        <i class="bi bi-upload"></i> Unggah Template SPT Baru
                    </h6>
                </div>
                <div class="card-body">
                    <form action="{{ route('spt-templates.store') }}" method="post" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nama Template</label>
                            <input type="text" name="nama" value="{{ old('nama') }}" class="form-control" required
                                placeholder="Template SPT V6 Revisi">
                            @error('nama')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Deskripsi (opsional)</label>
                            <input type="text" name="deskripsi" value="{{ old('deskripsi') }}" class="form-control"
                                placeholder="Catatan singkat">
                            @error('deskripsi')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">File DOCX</label>
                            <input type="file" name="file" class="form-control" accept=".docx" required>
                            <div class="form-text">Maksimal 5 MB, format DOCX. Template wajib berisi seluruh
                                placeholder standar SPT agar dapat digunakan.</div>
                            @error('file')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        </div>
                        <button class="btn btn-identity w-100">
                            <i class="bi bi-cloud-arrow-up-fill"></i> Unggah Template
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nama</th>
                                <th>Status</th>
                                <th>Ukuran</th>
                                <th>Dipakai</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($templates as $template)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="spt-thumb-frame is-paper flex-shrink-0"
                                                style="width: 84px; height: 112px;">
                                                <div class="spt-thumb-wrap" style="padding: 6px;">
                                                    @if ($template->existsThumbnail())
                                                        <canvas
                                                            data-spt-template-thumb="{{ route('spt-templates.thumbnail', $template) }}"
                                                            aria-label="Pratinjau {{ $template->nama }}"></canvas>
                                                        <div class="spt-thumb-fallback d-none"
                                                            style="inset: 6px; padding: .25rem;">
                                                            <i class="bi bi-file-earmark-text"
                                                                style="font-size: 1.5rem;"></i>
                                                        </div>
                                                    @else
                                                        <div class="spt-thumb-fallback"
                                                            style="inset: 6px; padding: .25rem;">
                                                            <i class="bi bi-file-earmark-text"
                                                                style="font-size: 1.5rem;"></i>
                                                            <small>Belum ada pratinjau</small>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                            <div>
                                                <div class="fw-semibold">
                                                    <i
                                                        class="bi bi-file-earmark-word text-primary me-1"></i>{{ $template->nama }}
                                                </div>
                                                @if ($template->deskripsi)
                                                    <small class="text-muted">{{ $template->deskripsi }}</small>
                                                @endif
                                                <div class="small text-muted">
                                                    {{ $template->original_filename }} · diunggah oleh
                                                    {{ $template->creator?->nama_lengkap ?? '-' }} ·
                                                    {{ $template->created_at?->format('d/m/Y H:i') }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                            {{ $template->is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                        @if ($template->is_default)
                                            <span class="badge text-bg-primary">Default</span>
                                        @endif
                                    </td>
                                    <td>{{ $template->humanReadableSize() }}</td>
                                    <td>{{ $template->perjalanan_dinas_count ?? $template->usageCount() }} SPT</td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            @if (!$template->is_default && $template->is_active)
                                                <form action="{{ route('spt-templates.default', $template) }}"
                                                    method="post">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                                        title="Jadikan template default">
                                                        <i class="bi bi-star"></i> Default
                                                    </button>
                                                </form>
                                            @endif
                                            <a href="{{ route('spt-templates.download', $template) }}"
                                                class="btn btn-sm btn-outline-secondary" title="Unduh file">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <form action="{{ route('spt-templates.toggle', $template) }}" method="post">
                                                @csrf
                                                @if ($template->is_active)
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"
                                                        title="Nonaktifkan template">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                @else
                                                    <button type="submit" class="btn btn-sm btn-outline-success"
                                                        title="Aktifkan template">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                @endif
                                            </form>
                                            <form action="{{ route('spt-templates.destroy', $template) }}" method="post"
                                                class="d-inline" data-sim-confirm data-sim-confirm-title="Hapus template?"
                                                data-sim-confirm-text="Template ini akan dihapus secara permanen. Template yang masih dipakai SPT tidak dapat dihapus."
                                                data-sim-confirm-button="Ya, hapus" data-sim-confirm-tone="danger">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    title="Hapus template">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <x-ui.empty-state icon="file-earmark-word" title="Belum ada template"
                                            description="Unggah template DOCX SPT di panel sebelah kiri." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
