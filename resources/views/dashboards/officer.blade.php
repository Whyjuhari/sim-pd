@extends('layouts.app')

@section('title', 'Dashboard Officer - SIM-PD')
@section('brand', 'SIM-PD Officer')

@section('content')

    <div class="card shadow-sm">

        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">

            <div>
                <h6 class="mb-0 fw-bold text-identity">
                    <i class="bi bi-file-earmark-text"></i>
                    Daftar Surat Perintah Tugas (SPT)
                </h6>

                <small class="text-muted">
                    Kelola Surat Tugas perjalanan dinas
                </small>
            </div>


            <a href="{{ route('travel-orders.create') }}" class="btn btn-identity btn-sm">
                <i class="bi bi-plus-circle"></i>
                Buat SPT Baru
            </a>

        </div>

        <div class="card-body border-bottom bg-light">

            <form method="GET" action="{{ route('dashboard.officer') }}">

                <div class="row g-2 align-items-end">


                    {{-- SEARCH --}}
                    <div class="col-lg-5 col-md-12">

                        <label for="search" class="form-label small fw-semibold">
                            Cari SPT
                        </label>

                        <div class="input-group">

                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input type="text" id="search" name="q" value="{{ $filters['q'] }}"
                                class="form-control" placeholder="Nomor SPT, pegawai, memo, tujuan...">

                        </div>

                    </div>


                    {{-- FILTER STATUS --}}
                    <div class="col-lg-3 col-md-6">

                        <label for="status" class="form-label small fw-semibold">
                            Status
                        </label>

                        <select id="status" name="status" class="form-select">

                            <option value="">
                                Semua Status
                            </option>

                            @foreach ($statusOptions as $statusValue => $statusLabel)
                                <option value="{{ $statusValue }}" @selected($filters['status'] === $statusValue)>
                                    {{ $statusLabel }}
                                </option>
                            @endforeach

                        </select>

                    </div>


                    {{-- FILTER TUJUAN --}}
                    <div class="col-lg-2 col-md-6">

                        <label for="destination" class="form-label small fw-semibold">
                            Kota Tujuan
                        </label>

                        <select id="destination" name="destination" class="form-select">

                            <option value="">
                                Semua Kota
                            </option>

                            @foreach ($destinationOptions as $destination)
                                <option value="{{ $destination }}" @selected($filters['destination'] === $destination)>
                                    {{ $destination }}
                                </option>
                            @endforeach

                        </select>

                    </div>


                    {{-- BUTTON --}}
                    <div class="col-lg-2 col-md-12">

                        <div class="d-flex gap-2">

                            <button type="submit" class="btn btn-primary flex-fill">
                                <i class="bi bi-funnel"></i>
                                Terapkan
                            </button>


                            <a href="{{ route('dashboard.officer') }}" class="btn btn-outline-secondary"
                                title="Reset Filter">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                        </div>

                    </div>

                </div>

            </form>
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">

                <small class="text-muted">

                    Menampilkan

                    <strong>{{ $sptGroups->firstItem() ?? 0 }}–{{ $sptGroups->lastItem() ?? 0 }}</strong>
                    dari <strong>{{ $sptGroups->total() }}</strong> hasil
                    ({{ $totalSpt }} total Surat Tugas).

                </small>

                @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['destination'] !== '')
                    <small class="text-muted">

                        <i class="bi bi-funnel-fill"></i>
                        Filter sedang aktif

                    </small>
                @endif

            </div>

        </div>
        <div class="card-body table-responsive">

            <table class="table table-hover align-middle table-actions-sticky">

                <thead class="table-light">

                    <tr>

                        <th>
                            No. SPT
                        </th>
                        <th>
                            Pegawai
                        </th>

                        <th>
                            Tujuan & Tanggal
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Aksi
                        </th>

                    </tr>

                </thead>


                <tbody>

                    @forelse($sptGroups as $group)
                        @php
                            $travel = $group['travel'];
                        @endphp
                        <tr>
                            <td class="fw-bold">
                                {{ $travel->no_spt }}
                            </td>
                            <td>
                                <ol class="mb-1 ps-3">

                                    @foreach ($group['employees'] as $employee)
                                        <li>
                                            {{ $employee->nama_lengkap }}
                                        </li>
                                    @endforeach

                                </ol>

                                <small class="text-muted">
                                    {{ $group['employees']->count() }}
                                    pegawai dalam satu Surat Tugas
                                </small>

                            </td>
                            <td>
                                <div class="fw-semibold">

                                    <i class="bi bi-geo-alt"></i>
                                    {{ $travel->kota_tujuan }}

                                </div>
                                <small class="text-muted">
                                    {{ $travel->tgl_berangkat->format('d/m/Y') }}

                                    -

                                    {{ $travel->tgl_kembali->format('d/m/Y') }}

                                    <br>

                                    {{ $travel->lama_hari }}
                                    Hari
                                </small>
                            </td>
                            <td>
                                @foreach ($group['travels'] as $memberTravel)
                                    <div class="mb-1 text-nowrap">

                                        <span class="badge status-{{ $memberTravel->status }}">
                                            {{ \App\Support\TravelStatus::label($memberTravel->status) }}
                                        </span>
                                    </div>
                                @endforeach
                            </td>
                            <td>

                                <div class="d-flex flex-wrap gap-2">

                                    <a href="{{ route('travel-orders.show', [
                                        'sptGroupId' => $travel->spt_group_id,
                                    ]) }}"
                                        class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                        Detail
                                    </a>
                                    <a target="_blank"
                                        href="{{ route('documents.surat-tugas', [
                                            'id' => $travel->id,
                                        ]) }}"
                                        class="btn btn-sm btn-primary">
                                        <i class="bi bi-printer"></i>
                                        Cetak
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-search fs-2 d-block mb-2"></i>
                                    @if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['destination'] !== '')
                                        Tidak ada Surat Tugas
                                        yang sesuai dengan
                                        pencarian atau filter.
                                        <div class="mt-3">

                                            <a href="{{ route('dashboard.officer') }}"
                                                class="btn btn-sm btn-outline-secondary">
                                                Reset Pencarian
                                            </a>

                                        </div>
                                    @else
                                        Belum ada data
                                        Surat Tugas.
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($sptGroups->hasPages())
            <div class="card-footer bg-white">{{ $sptGroups->links() }}</div>
        @endif
    </div>
@endsection
