@extends('layouts.app')
@section('title', 'Dashboard Admin - SIM-PD')
@section('brand', 'Administrasi SIM-PD')

@section('content')
    <div class="row g-3 mb-4">
        @foreach ([['Total Pegawai', $stats['users'], 'primary', 'people'], ['Siap Berjalan', $stats['ready'], 'warning', 'send'], ['Menunggu Verifikasi', $stats['pending'], 'info', 'hourglass-split'], ['Selesai', $stats['approved'], 'success', 'check-circle']] as [$label, $value, $color, $icon])
            <div class="col-md-3">
                <div class="card shadow-sm h-100 border-start border-5 border-{{ $color }}">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div><small class="text-muted text-uppercase fw-bold">{{ $label }}</small>
                            <h2 class="mb-0">{{ $value }}</h2>
                        </div>
                        <i class="bi bi-{{ $icon }} fs-1 text-{{ $color }} opacity-50"></i>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="{{ route('employees.index') }}" class="btn btn-primary"><i class="bi bi-people"></i> Kelola Pegawai</a>
        <a href="{{ route('employees.roles') }}" class="btn btn-outline-primary"><i class="bi bi-person-badge"></i> Kelola
            Role Lain</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3">Top 5 Pegawai Paling Sering Dinas</div>
                <div class="card-body"><canvas id="chartPegawai"></canvas></div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-bold py-3">Monitoring Transaksi Terbaru</div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle small">
                        <thead class="table-light">
                            <tr>
                                <th>No SPT</th>
                                <th>Pegawai</th>
                                <th>Tujuan</th>
                                <th>Status</th>
                                <th>Dokumen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($travels as $travel)
                                <tr>
                                    <td class="fw-bold">{{ $travel->no_spt }}</td>
                                    <td>{{ $travel->pegawai->nama_lengkap }}</td>
                                    <td>{{ $travel->kota_tujuan }}<br><small
                                            class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}</small></td>
                                    <td><span class="badge status-{{ $travel->status }}">{{ \App\Support\TravelStatus::label($travel->status) }}</span></td>

                                    <td>
                                        @can('printTravelDocument', $travel)
                                            <a target="_blank" href="{{ route('documents.perjadin', ['id' => $travel->id]) }}"
                                                class="btn btn-outline-dark btn-sm" title="Cetak Dokumen Perjalanan">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        @else
                                            <button type="button" class="btn btn-light border btn-sm" disabled
                                                title="Dokumen belum dapat dicetak">
                                                <i class="bi bi-printer text-muted"></i>
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Belum ada transaksi.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        @if($travels->hasPages())
                            <div class="mt-3">{{ $travels->links() }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endsection

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => new Chart(document.getElementById('chartPegawai'), {
                type: 'doughnut',
                data: {
                    labels: @json($topEmployees->pluck('nama_lengkap')),
                    datasets: [{
                        data: @json($topEmployees->pluck('jumlah_trip')),
                        backgroundColor: ['#224369', '#3e6b99', '#6c8ebf', '#a3c2e0', '#d6e4f0']
                    }]
                }
            }));
        </script>
    @endpush
