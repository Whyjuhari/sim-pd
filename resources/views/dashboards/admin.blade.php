@extends('layouts.app')
@section('title', 'Dashboard Admin - SIM-PD')
@section('brand', 'Administrasi SIM-PD')
@section('page-subtitle', 'Pantau pengguna, perjalanan dinas, dokumen, dan kesiapan sistem dalam satu ruang kerja.')
@section('page-actions')
    <a href="{{ route('admin.system-health') }}" class="btn btn-outline-primary">
        <i class="bi bi-heart-pulse"></i> Kesehatan Sistem
    </a>
@endsection

@section('content')
    <div class="row g-3 mb-4">
        @foreach ([['Total Pegawai', $stats['users'], 'people-fill', 'primary', 'Pengguna role pegawai'], ['Siap Berjalan', $stats['ready'], 'send-fill', 'info', 'SPT aktif'], ['Menunggu Verifikasi', $stats['pending'], 'hourglass-split', 'warning', 'Perlu diproses'], ['Selesai', $stats['approved'], 'check-circle-fill', 'success', 'Telah dicairkan']] as [$label, $value, $icon, $tone, $hint])
            <div class="col-12 col-sm-6 col-xl-3">
                <x-ui.metric-card :label="$label" :value="$value" :icon="$icon" :tone="$tone"
                    :hint="$hint" />
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-pie-chart-fill"></i></span>
                        Pegawai Paling Aktif</h2>
                </div>
                <div class="card-body chart-shell"><canvas id="chartPegawai"></canvas></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-activity"></i></span>
                        Transaksi Terbaru</h2>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>No SPT</th>
                                <th>Pegawai</th>
                                <th>Tujuan & Tanggal</th>
                                <th>Status</th>
                                <th>Dokumen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($travels as $travel)
                                <tr>
                                    <td class="fw-bold text-identity">{{ $travel->no_spt }}</td>
                                    <td>{{ $travel->pegawai->nama_lengkap }}</td>
                                    <td><span class="fw-semibold">{{ $travel->kota_tujuan }}</span><br><small
                                            class="text-muted">{{ $travel->tgl_berangkat->format('d/m/Y') }}</small></td>
                                    <td><x-ui.status-pill :status="$travel->status" /></td>
                                    <td>
                                        @can('printTravelDocument', $travel)
                                            <a target="_blank" href="{{ route('documents.perjadin', ['id' => $travel->id]) }}"
                                                class="btn btn-outline-primary btn-sm"><i class="bi bi-printer"></i> Cetak</a>
                                        @else
                                            <span class="text-muted small"><i class="bi bi-lock"></i> Belum tersedia</span>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5"><x-ui.empty-state icon="inbox" title="Belum ada transaksi"
                                            description="Perjalanan dinas terbaru akan muncul di bagian ini." /></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($travels->hasPages())
                    <div class="card-footer bg-white">{{ $travels->links() }}</div>
                @endif
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
                    backgroundColor: ['#1d4168', '#1f7a78', '#4d7da9', '#b9892d', '#9ab7cf'],
                    borderColor: '#ffffff',
                    borderWidth: 3
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 14
                        }
                    }
                }
            }
        }));
    </script>
@endpush
