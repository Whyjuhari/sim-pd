@extends('layouts.app')
@section('title', 'Dashboard Pegawai - SIM-PD')
@section('brand', 'E-PERJADIN')

@section('content')
    @if (session('warning'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
            {{ session('warning') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    @endif

    <div class="card bg-identity text-white shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center p-4">
            <div>
                <div class="fw-light">Halo, Selamat Bekerja!</div>
                <h3 class="mb-0">{{ auth()->user()->nama_lengkap }}</h3>
            </div>
            <a href="{{ route('profile.edit') }}" class="btn btn-light"><img src="{{ auth()->user()->photoUrl() }}"
                    class="avatar-sm me-2" alt="Profil">Profil Saya</a>
        </div>
    </div>

    @if ($newTaskCount > 0)
        <div class="alert alert-light border shadow-sm rounded-4"><i
                class="bi bi-bell-fill text-danger fs-4 me-2"></i><strong>Ada {{ $newTaskCount }} Tugas Baru!</strong>
            Periksa perjalanan yang perlu dilaporkan atau diperbaiki.</div>
    @endif

    <div class="workflow-steps mb-4" aria-label="Tahapan perjalanan dinas">
        <span>1. SPT</span><span>2. Laporan</span><span>3. Realisasi</span><span>4. Verifikasi</span><span>5. Selesai / Revisi</span>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold text-identity">Riwayat Perjalanan Dinas</h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle table-actions-sticky">
                <thead class="table-light">
                    <tr>
                        <th>No SPT</th>
                        <th>Tujuan</th>
                        <th>Tanggal</th>
                        {{-- <th>Estimasi</th> --}}
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($travels as $travel)
                        <tr>
                            <td class="fw-bold">{{ $travel->no_spt }}</td>
                            <td>{{ $travel->kota_tujuan }}</td>
                            <td>{{ $travel->tgl_berangkat->format('d/m/Y') }} s/d
                                {{ $travel->tgl_kembali->format('d/m/Y') }}</td>
                            {{-- <td>Rp {{ number_format((float) $travel->estimasi_biaya, 0, ',', '.') }}</td> --}}
                            <td>
                                @if ($travel->status === 'siap_jalan')
                                    @if ($travel->laporan)
                                        <span class="badge bg-info text-dark">
                                            Laporan Tersimpan / Realisasi Belum Dikirim
                                        </span>
                                    @else
                                        <span class="badge bg-secondary">Belum Lapor</span>
                                    @endif
                                @elseif($travel->status === 'pending_verif')
                                    <span class="badge bg-warning text-dark">Menunggu Verifikasi</span>
                                @elseif($travel->status === 'approved')
                                    <span class="badge bg-success">Selesai</span>
                                @elseif($travel->status === 'rejected')
                                    <span class="badge bg-danger">Perlu Revisi Realisasi</span>
                                @else
                                    <span class="badge bg-light text-dark">{{ $travel->status }}</span>
                                @endif
                            </td>
                            <td>

                                <div class="d-flex flex-wrap gap-2">
                                    @if ($travel->laporan)
                                        @if ($hasSignature)
                                            <a target="_blank"
                                                href="{{ route('documents.laporan-perjadin', [
                                                    'id' => $travel->id,
                                                ]) }}"
                                                class="btn btn-outline-success btn-sm">
                                                <i class="bi bi-file-earmark-text"></i>
                                                Cetak Laporan
                                            </a>
                                        @else
                                            <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                                                title="Tanda tangan belum tersedia">
                                                <i class="bi bi-file-earmark-text"></i>
                                                Cetak Laporan
                                            </button>
                                            <span class="small text-warning-emphasis align-self-center">
                                                Tanda tangan belum tersedia. Hubungi Admin.
                                            </span>
                                        @endif
                                    @endif
                                    @can('printSuratTugas', $travel)
                                        <a target="_blank"
                                            href="{{ route('documents.surat-tugas', [
                                                'id' => $travel->id,
                                            ]) }}"
                                            class="btn btn-outline-dark btn-sm" title="Cetak Surat Tugas">
                                            <i class="bi bi-file-earmark-text"></i>
                                            Cetak SPT
                                        </a>
                                    @endcan
                                    @if ($travel->status === \App\Models\PerjalananDinas::STATUS_READY)
                                        @if ($travel->laporan)
                                            <a href="{{ route('realizations.show', [
                                                'id' => $travel->id,
                                            ]) }}"
                                                class="btn btn-primary btn-sm">
                                                <i class="bi bi-arrow-right-circle"></i>
                                                Lanjutkan Realisasi
                                            </a>
                                        @else
                                            <a href="{{ route('travel-reports.edit', [
                                                'travel' => $travel->id,
                                            ]) }}"
                                                class="btn btn-primary btn-sm">
                                                <i class="bi bi-upload"></i>
                                                Isi Laporan
                                            </a>
                                        @endif
                                    @elseif($travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED)
                                        <a href="{{ route('realizations.show', ['id' => $travel->id]) }}"
                                            class="btn btn-danger btn-sm">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            Perbaiki Realisasi
                                        </a>
                                    @elseif($travel->status === \App\Models\PerjalananDinas::STATUS_APPROVED)
                                        @can('printTravelDocument', $travel)
                                            <a target="_blank"
                                                href="{{ route('documents.perjadin', [
                                                    'id' => $travel->id,
                                                ]) }}"
                                                class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-printer"></i>
                                                Cetak Dokumen Perjalanan
                                            </a>
                                        @endcan
                                    @else
                                        <button type="button" class="btn btn-light border btn-sm" disabled>
                                            <i class="bi bi-hourglass-split"></i>
                                            Sedang Diproses
                                        </button>
                                    @endif

                                </div>

                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">Belum ada riwayat perjalanan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($travels->hasPages())
            <div class="card-footer bg-white">{{ $travels->links() }}</div>
        @endif
    </div>
@endsection
