@extends('layouts.app')

@php
    $processPreviewId = $srikandiWorkflow ? 'spt-process-preview-' . $srikandiWorkflow->id : null;
    $safeReference = preg_replace('/[^A-Za-z0-9._-]+/', '_', $travel->spt_internal_reference ?? 'SPT') ?: 'SPT';
    $latestProcessVersion = $srikandiWorkflow?->latestVersion();
    $processComplete = $srikandiWorkflow?->status === \App\Models\SptSrikandiWorkflow::STATUS_PUBLISHED;
@endphp

@section('title', 'Detail SPT - SIM-PD')
@section('brand', 'Detail Surat Tugas')
@section('page-actions')
    <a href="{{ $detailBackRoute }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
@endsection

@section('content')
    @if ($srikandiWorkflow)
        @include('travel.partials.officer-spt-process')
    @else
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-identity">
                    <i class="bi bi-file-earmark-text"></i>
                    Informasi Surat Tugas
                </h6>
            </div>
            <div class="card-body">

                <div class="row g-3 mb-3">

                    <div class="col-md-4">
                        <div class="text-muted small">
                            {{ $srikandiWorkflow ? 'Kode SPT' : 'Nomor / Referensi SPT' }}
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->sptOperationalReference() }}
                        </div>
                    </div>

                    @if (
                        !$srikandiWorkflow &&
                            $travel->spt_internal_reference &&
                            $travel->sptOperationalReference() !== $travel->spt_internal_reference)
                        <div class="col-md-4">
                            <div class="text-muted small">Kode SPT</div>
                            <div class="fw-semibold">{{ $travel->spt_internal_reference }}</div>
                        </div>
                    @endif

                    <div class="col-md-4">
                        <div class="text-muted small">Mode Penomoran</div>
                        <div class="fw-semibold">
                            {{ $travel->spt_number_mode === \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL
                                ? 'Proses lewat SRIKANDI'
                                : 'Isi nomor sendiri' }}
                        </div>
                    </div>

                    @if (
                        !$srikandiWorkflow &&
                            $travel->spt_number_mode === \App\Models\PerjalananDinas::NUMBER_MODE_EXTERNAL &&
                            $travel->spt_external_number)
                        <div class="col-md-4">
                            <div class="text-muted small">Nomor surat tercatat</div>
                            <div class="fw-semibold">{{ $travel->spt_external_number }}</div>
                        </div>
                    @endif

                    @if ($travel->no_memo || $travel->tgl_memo)
                        <div class="col-md-4">
                            <div class="text-muted small">
                                Nomor Memo Internal
                            </div>

                            <div class="fw-semibold">
                                {{ $travel->no_memo ?: '-' }}
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="text-muted small">
                                Tanggal Memo
                            </div>

                            <div class="fw-semibold">
                                {{ $travel->tgl_memo?->format('d/m/Y') ?? '-' }}
                            </div>
                        </div>
                    @endif

                </div>


                @if ($travel->perihal_memo)
                    <div class="mb-3">
                        <div class="text-muted small">
                            Perihal Memo Internal
                        </div>

                        <div>
                            {{ $travel->perihal_memo }}
                        </div>
                    </div>
                @endif


                <div class="mb-3">
                    <div class="text-muted small">
                        Menimbang
                    </div>

                    <div>
                        {{ $travel->menimbang }}
                    </div>
                </div>


                <div>
                    <div class="text-muted small">
                        Maksud Perjalanan Dinas
                    </div>

                    <div>
                        {{ $travel->maksud_perjalanan }}
                    </div>
                </div>

            </div>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-identity">
                    <i class="bi bi-file-earmark-text"></i>
                    Template SPT
                </h6>
            </div>
            <div class="card-body">
                <div class="text-muted small">
                    Template yang digunakan untuk mencetak surat ini
                </div>
                <div class="fw-semibold">
                    {{ $travel->sptTemplateLabel() }}
                </div>
                @if ($travel->usesDipaTemplate())
                    <div class="small text-muted mt-1">
                        DIPA TA {{ $travel->dipa_fiscal_year_snapshot }} · {{ $travel->dipa_number_snapshot }} ·
                        {{ $travel->dipa_date_snapshot?->format('d/m/Y') }}
                    </div>
                @endif
            </div>
        </div>

        @if ($canRecordSrikandiNumber)
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-hash"></i> Catat Nomor Srikandi</h6>
                    <form method="POST"
                        action="{{ route('travel-orders.record-srikandi-number', ['sptGroupId' => $travel->spt_group_id]) }}"
                        class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md">
                            <label for="spt_external_number" class="form-label fw-semibold">Nomor dari Srikandi</label>
                            <input id="spt_external_number" name="spt_external_number" type="text" class="form-control"
                                maxlength="50" value="{{ old('spt_external_number') }}" required>
                            @error('spt_external_number')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Opsional dan hanya dapat dicatat satu kali. Surat Tugas tetap memakai
                                ${nomor_naskah}.</div>
                        </div>
                        <div class="col-md-auto d-grid">
                            <button class="btn btn-primary" data-sim-confirm data-sim-confirm-title="Catat nomor Srikandi?"
                                data-sim-confirm-text="Nomor akan menjadi referensi dokumen lanjutan dan tidak mengubah isi Surat Tugas."
                                data-sim-confirm-button="Ya, catat nomor"><i class="bi bi-check-circle"></i> Catat</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold text-identity">
                    <i class="bi bi-geo-alt"></i>
                    Detail Perjalanan
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Tempat Berangkat
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->tempat_berangkat }}
                        </div>
                    </div>


                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Kota Tujuan
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->kota_tujuan }}
                        </div>
                    </div>


                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Jenis Angkutan
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->angkutan }}
                        </div>
                    </div>


                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Tanggal Berangkat
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->tgl_berangkat?->format('d/m/Y') ?? '-' }}
                        </div>
                    </div>


                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Tanggal Kembali
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->tgl_kembali?->format('d/m/Y') ?? '-' }}
                        </div>
                    </div>


                    <div class="col-md-4 mb-3">
                        <div class="text-muted small">
                            Lama Perjalanan
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->lama_hari }} Hari
                        </div>
                    </div>


                    <div class="col-md-6">
                        <div class="text-muted small">
                            Akun Anggaran
                        </div>

                        <div class="fw-semibold">
                            {{ $travel->akun_anggaran }}
                        </div>
                    </div>


                    <div class="col-md-6">
                        <div class="text-muted small">
                            Estimasi Biaya per Pegawai
                        </div>

                        <div class="fw-semibold">
                            Rp
                            {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}
                        </div>
                    </div>

                </div>

            </div>
        </div>
        <div class="card shadow-sm mb-4">

            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">

                <h6 class="mb-0 fw-bold text-identity">
                    <i class="bi bi-people"></i>
                    Pegawai yang Ditugaskan
                </h6>

                <span class="badge bg-secondary">
                    {{ $travels->count() }} Pegawai
                </span>

            </div>

            <div class="card-body table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px">
                                No.
                            </th>

                            <th>
                                Nama Pegawai
                            </th>

                            <th>
                                NIP
                            </th>

                            <th>
                                Pangkat / Golongan
                            </th>

                            <th>
                                Jabatan
                            </th>

                            <th>
                                Status
                            </th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($travels as $memberTravel)
                            <tr>

                                <td>
                                    {{ $loop->iteration }}
                                </td>

                                <td class="fw-semibold">
                                    {{ $memberTravel->pegawai?->nama_lengkap ?? '-' }}
                                </td>

                                <td>
                                    {{ $memberTravel->pegawai?->nip ?? '-' }}
                                </td>

                                <td>
                                    {{ $memberTravel->pegawai?->pangkat_golongan ?? '-' }}
                                </td>

                                <td>
                                    {{ $memberTravel->pegawai?->jabatan ?? '-' }}
                                </td>

                                <td>

                                    <span class="badge status-{{ $memberTravel->status }}">
                                        {{ \App\Support\TravelStatus::label($memberTravel->status) }}
                                    </span>

                                </td>

                            </tr>
                        @endforeach

                    </tbody>

                </table>

            </div>
        </div>
        @if ($canModify)
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i>
                Surat Tugas ini masih dapat <strong>diedit{{ $canDelete ? ' atau dihapus' : '' }}</strong>.
                @if (!$canDelete)
                    Riwayat konsep yang pernah dikirim tetap disimpan.
                @endif
            </div>
        @elseif (
            $srikandiWorkflow &&
                !in_array(
                    $srikandiWorkflow->status,
                    [\App\Models\SptSrikandiWorkflow::STATUS_DRAFT, \App\Models\SptSrikandiWorkflow::STATUS_REVISION],
                    true))
            <div class="alert alert-info">
                <i class="bi bi-lock"></i>
                Data Surat Tugas dikunci karena konsep sudah ditandai dikirim.
            </div>
        @else
            <div class="alert alert-warning">
                <i class="bi bi-lock"></i>

                Surat Tugas ini tidak dapat diedit atau
                dihapus karena proses perjalanan sudah berjalan
                pada salah satu atau beberapa pegawai.
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2">

            @if ($canModify)
                <a href="{{ route(
                    'travel-orders.edit',
                    [
                        'sptGroupId' => $travel->spt_group_id,
                    ] + $detailContext,
                ) }}"
                    class="btn btn-warning">
                    <i class="bi bi-pencil-square"></i>
                    Edit SPT
                </a>
            @endif

            @if ($canDelete)
                <form
                    action="{{ route(
                        'travel-orders.destroy',
                        [
                            'sptGroupId' => $travel->spt_group_id,
                        ] + $detailContext,
                    ) }}"
                    method="POST" class="d-inline" data-sim-confirm data-sim-confirm-title="Hapus SPT kolektif?"
                    data-sim-confirm-text="Semua data pegawai yang tergabung dalam SPT ini akan dihapus. Tindakan ini tidak dapat dibatalkan."
                    data-sim-confirm-button="Ya, hapus SPT" data-sim-confirm-tone="danger">

                    @csrf
                    @method('DELETE')

                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i>
                        Hapus SPT
                    </button>

                </form>
            @endif

        </div>
    @endif
@endsection
