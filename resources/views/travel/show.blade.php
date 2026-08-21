@extends('layouts.app')

@section('title', 'Detail SPT - SIM-PD')
@section('brand', 'Detail Surat Tugas')

@section('content')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">
                Detail Surat Tugas
            </h4>

            <div class="text-muted">
                {{ $travel->no_spt }}
            </div>
        </div>

        <a href="{{ route('dashboard.officer') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Kembali
        </a>
    </div>

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold text-identity">
                <i class="bi bi-file-earmark-text"></i>
                Informasi Surat Tugas
            </h6>
        </div>

        <div class="card-body">

            <div class="row mb-3">

                <div class="col-md-4">
                    <div class="text-muted small">
                        Nomor SPT
                    </div>

                    <div class="fw-semibold">
                        {{ $travel->no_spt }}
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="text-muted small">
                        Nomor Memo Internal
                    </div>

                    <div class="fw-semibold">
                        {{ $travel->no_memo }}
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

            </div>


            <div class="mb-3">
                <div class="text-muted small">
                    Perihal Memo Internal
                </div>

                <div>
                    {{ $travel->perihal_memo }}
                </div>
            </div>


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

            Surat Tugas ini masih dapat
            <strong>diedit atau dihapus</strong>
            karena seluruh pegawai masih berstatus
            <strong>Siap Berjalan</strong>.
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

        <a href="{{ route('dashboard.officer') }}" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i>
            Kembali
        </a>


        <a target="_blank"
            href="{{ route('documents.surat-tugas', [
                'id' => $travel->id,
            ]) }}"
            class="btn btn-primary">
            <i class="bi bi-printer"></i>
            Cetak Surat Tugas
        </a>


        @if ($canModify)
            {{-- EDIT --}}
            <a href="{{ route('travel-orders.edit', [
                'sptGroupId' => $travel->spt_group_id,
            ]) }}"
                class="btn btn-warning">
                <i class="bi bi-pencil-square"></i>
                Edit SPT
            </a>


            {{-- DELETE --}}
            <form
                action="{{ route('travel-orders.destroy', [
                    'sptGroupId' => $travel->spt_group_id,
                ]) }}"
                method="POST" class="d-inline"
                onsubmit="
                return confirm(
                    'Yakin ingin menghapus Surat Tugas ini? Semua data pegawai yang tergabung dalam SPT ini akan dihapus.'
                );
            ">

                @csrf
                @method('DELETE')

                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i>
                    Hapus SPT
                </button>

            </form>
        @endif

    </div>

@endsection
