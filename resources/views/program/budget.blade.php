@extends('layouts.app')
@section('title', 'Master Anggaran - SIM-PD')
@section('brand', 'Master Anggaran')
@section('page-actions')
    <a href="{{ route('dashboard.program') }}" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left"></i> Kembali ke Monitoring</a>
@endsection

@section('content')
    <section class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-shield-check"></i></span> Kesiapan
                Data PMK</h2>
            <form method="GET" class="d-flex gap-2">
                @if ($search !== '')
                    <input type="hidden" name="q" value="{{ $search }}">
                @endif
                <label for="readiness_year" class="visually-hidden">Tahun anggaran</label>
                <select id="readiness_year" name="readiness_year" class="form-select" onchange="this.form.submit()">
                    @foreach ($readinessYears as $year)
                        <option value="{{ $year }}" @selected($pmkReadiness['year'] === $year)>TA {{ $year }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span
                    class="badge rounded-pill text-bg-{{ $pmkReadiness['ready'] ? 'success' : 'warning' }}">{{ $pmkReadiness['ready'] ? 'Siap' : 'Perlu perhatian' }}</span>
                <span class="small text-muted">{{ $pmkReadiness['dataset_ready_count'] }}/4 dataset aktif</span>
            </div>
            <div class="row g-2 mb-3">
                @foreach ($pmkReadiness['datasets'] as $dataset)
                    <div class="col-6 col-xl-3">
                        <div class="pmk-readiness-dataset">
                            <span>{{ $dataset['label'] }}</span>
                            <strong
                                class="text-{{ $dataset['ready'] ? 'success' : 'warning' }}">{{ $dataset['ready'] ? 'Aktif' : 'Belum siap' }}</strong>
                            <small>{{ $dataset['actual'] }}/{{ $dataset['expected'] }}{{ $dataset['revision'] ? ' · R' . $dataset['revision'] : '' }}</small>
                        </div>
                    </div>
                @endforeach
            </div>
            @php
                $mappingLabels = [
                    'missing_province' => 'Tanpa provinsi',
                    'missing_airport' => 'Tanpa bandara',
                    'terminal_unavailable' => 'Terminal kosong',
                    'airfare_fallback' => 'Tiket fallback',
                    'ground_legacy' => 'Darat legacy',
                ];
            @endphp
            <div class="pmk-readiness-mappings">
                @foreach ($mappingLabels as $key => $label)
                    @php($issue = $pmkReadiness['mappings'][$key])
                    <div title="{{ implode(', ', $issue['examples']) }}">
                        <strong
                            class="{{ $issue['count'] > 0 ? 'text-warning-emphasis' : 'text-success' }}">{{ $issue['count'] }}</strong>
                        <span>{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @php($selectedDipa = $dipaSettings->firstWhere('fiscal_year', $pmkReadiness['year']))
    <section class="card shadow-sm mb-4" id="dipa-settings">
        <div class="card-header bg-white">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i
                        class="bi bi-file-earmark-text"></i></span> DIPA TA {{ $pmkReadiness['year'] }}</h2>
        </div>
        <div class="card-body">
            <form method="POST"
                action="{{ route('program.dipa.update', ['fiscalYear' => $pmkReadiness['year']]) }}">
                @csrf
                @method('PUT')
                <div class="row g-3 align-items-end">
                    <div class="col-lg-7">
                        <label for="dipa_document_number" class="form-label fw-semibold">Nomor DIPA</label>
                        <input id="dipa_document_number" name="document_number" type="text" class="form-control"
                            maxlength="100" value="{{ old('document_number', $selectedDipa?->document_number) }}" required>
                    </div>
                    <div class="col-sm-7 col-lg-3">
                        <label for="dipa_document_date" class="form-label fw-semibold">Tanggal DIPA</label>
                        <input id="dipa_document_date" name="document_date" type="date" class="form-control"
                            value="{{ old('document_date', $selectedDipa?->document_date?->format('Y-m-d')) }}" required>
                    </div>
                    <div class="col-sm-5 col-lg-2 d-grid">
                        <button class="btn btn-primary"><i class="bi bi-check-circle"></i> Simpan</button>
                    </div>
                </div>
                @error('document_number')
                    <div class="text-danger small mt-2">{{ $message }}</div>
                @enderror
                @error('document_date')
                    <div class="text-danger small mt-2">{{ $message }}</div>
                @enderror
                @if ($selectedDipa)
                    <div class="form-text mt-2">Terakhir diperbarui
                        {{ $selectedDipa->updated_at?->format('d/m/Y H:i') }} oleh
                        {{ $selectedDipa->updater?->nama_lengkap ?? 'pengguna yang sudah tidak tersedia' }}.</div>
                @endif
            </form>
        </div>
    </section>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i
                        class="bi bi-file-earmark-spreadsheet"></i></span> Tarif Uang Harian PMK</h2>
            <a href="{{ route('program.daily-allowances.template') }}" class="btn btn-outline-success">
                <i class="bi bi-download"></i> Unduh Template CSV
            </a>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="border rounded-4 p-3 h-100">
                        <div class="fw-semibold mb-1">PMK Nomor 32 Tahun 2025</div>
                        <form method="POST" action="{{ route('program.daily-allowances.imports.upload') }}"
                            enctype="multipart/form-data">
                            @csrf
                            <label for="daily-allowance-csv" class="form-label fw-semibold">File tarif CSV</label>
                            <input id="daily-allowance-csv" type="file" name="csv" class="form-control"
                                accept=".csv,text/csv" required>
                            <div class="form-text">Maksimal 1 MB. Data diperiksa sebelum disimpan sebagai draft.
                            </div>
                            @error('csv')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                            <button class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Unggah & Periksa</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Versi</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($dailyAllowanceRegulations as $regulation)
                                    <tr>
                                        <td>
                                            <strong>TA {{ $regulation->fiscal_year }} · Revisi
                                                {{ $regulation->revision }}</strong><br>
                                            <small class="text-muted">{{ $regulation->created_at?->format('d/m/Y H:i') }} ·
                                                {{ $regulation->uploader?->nama_lengkap ?? 'Pengunggah tidak tersedia' }}</small>
                                        </td>
                                        <td>
                                            @if ($regulation->status === 'active')
                                                <span class="badge rounded-pill text-bg-success">Aktif</span>
                                            @elseif($regulation->status === 'draft')
                                                <span class="badge rounded-pill text-bg-warning">Draft</span>
                                            @else
                                                <span class="badge rounded-pill text-bg-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td>{{ $regulation->rates_count }} provinsi<br><small class="text-muted">SHA-256
                                                {{ substr($regulation->csv_sha256, 0, 10) }}…</small></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                <a href="{{ route('program.daily-allowances.download', $regulation) }}"
                                                    class="btn btn-sm btn-outline-success"
                                                    aria-label="Unduh CSV revisi {{ $regulation->revision }}"><i
                                                        class="bi bi-download"></i></a>
                                                @if ($regulation->status === 'draft')
                                                    <form method="POST"
                                                        action="{{ route('program.daily-allowances.activate', $regulation) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-primary" data-sim-confirm
                                                            data-sim-confirm-title="Aktifkan tarif PMK?"
                                                            data-sim-confirm-text="Tarif revisi {{ $regulation->revision }} akan digunakan untuk SPT baru TA {{ $regulation->fiscal_year }}. SPT lama tidak berubah."
                                                            data-sim-confirm-button="Ya, aktifkan tarif"
                                                            data-sim-confirm-tone="warning"><i
                                                                class="bi bi-check-circle"></i> Aktifkan</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="p-5 text-center text-muted py-4">Belum ada dataset PMK
                                            yang
                                            disimpan.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @error('pmk')
                        <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-signpost-split"></i></span>
                Transportasi Darat PMK</h2>
            <a href="{{ route('program.ground-transport.template') }}" class="btn btn-outline-success">
                <i class="bi bi-download"></i> Unduh Template CSV
            </a>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="border rounded-4 p-3 h-100">
                        <div class="fw-semibold mb-1">PMK Nomor 32 Tahun 2025</div>
                        <form method="POST" action="{{ route('program.ground-transport.imports.upload') }}"
                            enctype="multipart/form-data">
                            @csrf
                            <label for="ground-transport-csv" class="form-label fw-semibold">File tarif transportasi darat
                                CSV</label>
                            <input id="ground-transport-csv" type="file" name="ground_transport_csv"
                                class="form-control" accept=".csv,text/csv" required>
                            <div class="form-text">Maksimal 2 MB. Urutan rute boleh berbeda.</div>
                            @error('ground_transport_csv')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                            <button class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Unggah & Periksa</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Versi</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($groundTransportRegulations as $regulation)
                                    <tr>
                                        <td>
                                            <strong>TA {{ $regulation->fiscal_year }} · Revisi
                                                {{ $regulation->revision }}</strong><br>
                                            <small class="text-muted">{{ $regulation->created_at?->format('d/m/Y H:i') }}
                                                ·
                                                {{ $regulation->uploader?->nama_lengkap ?? 'Pengunggah tidak tersedia' }}</small>
                                        </td>
                                        <td>
                                            @if ($regulation->status === 'active')
                                                <span class="badge rounded-pill text-bg-success">Aktif</span>
                                            @elseif($regulation->status === 'draft')
                                                <span class="badge rounded-pill text-bg-warning">Draft</span>
                                            @else
                                                <span class="badge rounded-pill text-bg-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td>{{ $regulation->rates_count }} rute<br><small class="text-muted">SHA-256
                                                {{ substr($regulation->csv_sha256, 0, 10) }}…</small></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                <a href="{{ route('program.ground-transport.download', $regulation) }}"
                                                    class="btn btn-sm btn-outline-success"
                                                    aria-label="Unduh CSV transportasi darat revisi {{ $regulation->revision }}"><i
                                                        class="bi bi-download"></i></a>
                                                @if ($regulation->status === 'draft')
                                                    <form method="POST"
                                                        action="{{ route('program.ground-transport.activate', $regulation) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-primary" data-sim-confirm
                                                            data-sim-confirm-title="Aktifkan transportasi darat PMK?"
                                                            data-sim-confirm-text="Revisi {{ $regulation->revision }} akan digunakan untuk SPT darat baru yang rutenya tersedia. SPT lama dan pesawat tidak berubah."
                                                            data-sim-confirm-button="Ya, aktifkan tarif"
                                                            data-sim-confirm-tone="warning">
                                                            <i class="bi bi-check-circle"></i> Aktifkan
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Belum ada dataset
                                            transportasi darat PMK.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @error('ground_transport_pmk')
                        <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-airplane"></i></span>
                Transportasi Terminal & Tiket Pesawat PMK</h2>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('program.air-transport.terminal-template') }}" class="btn btn-outline-success"><i
                        class="bi bi-download"></i> CSV Terminal</a>
                <a href="{{ route('program.air-transport.airfare-template') }}" class="btn btn-outline-success"><i
                        class="bi bi-download"></i> CSV Tiket</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="border rounded-4 p-3 h-100">
                        <div class="fw-semibold mb-1">PMK Nomor 32 Tahun 2025</div>
                        <form method="POST" action="{{ route('program.air-transport.imports.upload') }}"
                            enctype="multipart/form-data">
                            @csrf
                            <label for="terminal-transport-csv" class="form-label fw-semibold">CSV transport
                                terminal</label>
                            <input id="terminal-transport-csv" type="file" name="terminal_transport_csv"
                                class="form-control" accept=".csv,text/csv" required>
                            @error('terminal_transport_csv')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                            <label for="airfare-csv" class="form-label fw-semibold mt-3">CSV tiket pesawat</label>
                            <input id="airfare-csv" type="file" name="airfare_csv" class="form-control"
                                accept=".csv,text/csv" required>
                            @error('airfare_csv')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Kedua CSV wajib diunggah bersama. Urutan baris boleh berbeda.</div>
                            <button class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Unggah & Periksa</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Versi</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($airTransportRegulations as $regulation)
                                    <tr>
                                        <td><strong>TA {{ $regulation->fiscal_year }} · Revisi
                                                {{ $regulation->revision }}</strong><br><small
                                                class="text-muted">{{ $regulation->created_at?->format('d/m/Y H:i') }} ·
                                                {{ $regulation->uploader?->nama_lengkap ?? 'Pengunggah tidak tersedia' }}</small>
                                        </td>
                                        <td>
                                            @if ($regulation->status === 'active')
                                                <span class="badge rounded-pill text-bg-success">Aktif</span>
                                            @elseif($regulation->status === 'draft')
                                            <span class="badge rounded-pill text-bg-warning">Draft</span>@else<span
                                                    class="badge rounded-pill text-bg-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td>{{ $regulation->terminal_rates_count }}
                                            provinsi<br>{{ $regulation->airfare_rates_count }} pasangan tiket</td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                <a href="{{ route('program.air-transport.download-terminal', $regulation) }}"
                                                    class="btn btn-sm btn-outline-success" title="Unduh CSV terminal"><i
                                                        class="bi bi-bus-front"></i></a>
                                                <a href="{{ route('program.air-transport.download-airfare', $regulation) }}"
                                                    class="btn btn-sm btn-outline-success" title="Unduh CSV tiket"><i
                                                        class="bi bi-airplane"></i></a>
                                                @if ($regulation->status === 'draft')
                                                    <form method="POST"
                                                        action="{{ route('program.air-transport.activate', $regulation) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-primary" data-sim-confirm
                                                            data-sim-confirm-title="Aktifkan transportasi udara PMK?"
                                                            data-sim-confirm-text="Kedua dataset revisi {{ $regulation->revision }} akan digunakan sebagai snapshot SPT pesawat baru. SPT lama tidak berubah."
                                                            data-sim-confirm-button="Ya, aktifkan tarif"
                                                            data-sim-confirm-tone="warning"><i
                                                                class="bi bi-check-circle"></i> Aktifkan</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty<tr>
                                        <td colspan="4" class="text-center text-muted py-4">Belum ada dataset
                                            transportasi udara PMK.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @error('air_transport_pmk')
                        <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                    @enderror
                    @error('air_transport_csv')
                        <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-building"></i></span> Tarif
                Penginapan PMK</h2>
            <a href="{{ route('program.hotel-rates.template') }}" class="btn btn-outline-success">
                <i class="bi bi-download"></i> Unduh Template CSV
            </a>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="border rounded-4 p-3 h-100">
                        <div class="fw-semibold mb-1">PMK Nomor 32 Tahun 2025</div>
                        <form method="POST" action="{{ route('program.hotel-rates.imports.upload') }}"
                            enctype="multipart/form-data">
                            @csrf
                            <label for="hotel-rate-csv" class="form-label fw-semibold">File tarif hotel CSV</label>
                            <input id="hotel-rate-csv" type="file" name="hotel_csv" class="form-control"
                                accept=".csv,text/csv" required>
                            <div class="form-text">CSV UTF-8, maksimal 1 MB. Urutan provinsi boleh berbeda.</div>
                            @error('hotel_csv')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                            <button class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Unggah & Periksa</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Versi</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($hotelRegulations as $regulation)
                                    <tr>
                                        <td>
                                            <strong>TA {{ $regulation->fiscal_year }} · Revisi
                                                {{ $regulation->revision }}</strong><br>
                                            <small class="text-muted">{{ $regulation->created_at?->format('d/m/Y H:i') }}
                                                ·
                                                {{ $regulation->uploader?->nama_lengkap ?? 'Pengunggah tidak tersedia' }}</small>
                                        </td>
                                        <td>
                                            @if ($regulation->status === 'active')
                                                <span class="badge rounded-pill text-bg-success">Aktif</span>
                                            @elseif($regulation->status === 'draft')
                                                <span class="badge rounded-pill text-bg-warning">Draft</span>
                                            @else
                                                <span class="badge rounded-pill text-bg-secondary">Nonaktif</span>
                                            @endif
                                        </td>
                                        <td>{{ $regulation->rates_count }} provinsi<br><small class="text-muted">SHA-256
                                                {{ substr($regulation->csv_sha256, 0, 10) }}…</small></td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-1 flex-wrap">
                                                <a href="{{ route('program.hotel-rates.download', $regulation) }}"
                                                    class="btn btn-sm btn-outline-success"
                                                    aria-label="Unduh CSV hotel revisi {{ $regulation->revision }}"><i
                                                        class="bi bi-download"></i></a>
                                                @if ($regulation->status === 'draft')
                                                    <form method="POST"
                                                        action="{{ route('program.hotel-rates.activate', $regulation) }}">
                                                        @csrf
                                                        <button class="btn btn-sm btn-primary" data-sim-confirm
                                                            data-sim-confirm-title="Aktifkan tarif penginapan PMK?"
                                                            data-sim-confirm-text="Revisi {{ $regulation->revision }} akan digunakan untuk SPT baru TA {{ $regulation->fiscal_year }}. SPT lama tidak berubah."
                                                            data-sim-confirm-button="Ya, aktifkan tarif"
                                                            data-sim-confirm-tone="warning">
                                                            <i class="bi bi-check-circle"></i> Aktifkan
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Belum ada dataset hotel
                                            PMK.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @error('hotel_pmk')
                        <div class="alert alert-danger mt-3 mb-0">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Master Tujuan & Pemetaan PMK</span>
                    <form method="GET" class="d-flex gap-2">
                        <input name="q" value="{{ $search }}" class="form-control form-control-sm"
                            placeholder="Cari kota">
                        <button class="btn btn-sm btn-outline-primary" aria-label="Cari tarif"><i
                                class="bi bi-search"></i></button>
                    </form>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('program.tariffs.store') }}" class="row g-2 mb-4">
                        @csrf
                        <div class="col-md-4"><label for="new-city" class="visually-hidden">Kota tujuan</label><input
                                id="new-city" name="kota_tujuan" class="form-control" placeholder="Kota tujuan"
                                required></div>
                        <div class="col-md-3"><label for="new-province" class="visually-hidden">Provinsi</label><select
                                id="new-province" name="province_id" class="form-select">
                                <option value="">Pilih provinsi</option>
                                @foreach ($provinces as $province)
                                    <option value="{{ $province->id }}">{{ $province->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label for="new-airport" class="visually-hidden">Kota bandara
                                PMK</label><select id="new-airport" name="airfare_city" class="form-select">
                                <option value="">Bandara belum dipetakan</option>
                                @foreach ($airfareCities as $city)
                                    <option value="{{ $city }}">{{ $city }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1"><button class="btn btn-success w-100" aria-label="Tambah tujuan"><i
                                    class="bi bi-plus-lg"></i></button></div>
                    </form>
                    @foreach ($tariffs as $tariff)
                        <form method="POST" action="{{ route('program.tariffs.update', $tariff) }}"
                            class="row g-2 align-items-center border-top py-2">
                            @csrf @method('PUT')
                            <div class="col-md-3"><input name="kota_tujuan" value="{{ $tariff->kota_tujuan }}"
                                    class="form-control form-control-sm" aria-label="Kota tujuan" required></div>
                            <div class="col-md-3"><select name="province_id" class="form-select form-select-sm"
                                    aria-label="Provinsi {{ $tariff->kota_tujuan }}">
                                    <option value="">Belum dipetakan</option>
                                    @foreach ($provinces as $province)
                                        <option value="{{ $province->id }}" @selected($tariff->province_id === $province->id)>
                                            {{ $province->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4"><select name="airfare_city" class="form-select form-select-sm"
                                    aria-label="Kota bandara {{ $tariff->kota_tujuan }}">
                                    <option value="">Bandara belum dipetakan</option>
                                    @foreach ($airfareCities as $city)
                                        <option value="{{ $city }}" @selected($tariff->airfare_city === $city)>
                                            {{ $city }}</option>
                                    @endforeach
                                </select></div>
                            <div class="col-md-2 d-flex gap-1">
                                <button class="btn btn-sm btn-outline-primary flex-fill">Simpan</button>
                                <button type="submit" form="delete-tariff-{{ $tariff->id }}"
                                    class="btn btn-sm btn-outline-danger"
                                    aria-label="Hapus tujuan {{ $tariff->kota_tujuan }}" data-sim-confirm
                                    data-sim-confirm-title="Hapus tujuan?"
                                    data-sim-confirm-text="Tujuan {{ $tariff->kota_tujuan }} akan dihapus. SPT lama tetap menggunakan snapshotnya."
                                    data-sim-confirm-button="Ya, hapus tujuan" data-sim-confirm-tone="danger"><i
                                        class="bi bi-trash"></i></button>
                            </div>
                        </form>
                        <form id="delete-tariff-{{ $tariff->id }}" method="POST"
                            action="{{ route('program.tariffs.destroy', $tariff) }}" class="d-none">@csrf
                            @method('DELETE')</form>
                    @endforeach
                    {{ $tariffs->links() }}
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">Mata Anggaran (MAK)</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('program.accounts.store') }}" class="mb-4">
                        @csrf
                        <label for="new-account" class="form-label">Kode MAK</label>
                        <input id="new-account" name="code" maxlength="50" class="form-control mb-2" required>
                        <label for="new-account-description" class="form-label">Keterangan</label>
                        <input id="new-account-description" name="description" class="form-control mb-2">
                        <button class="btn btn-success w-100">Tambah MAK</button>
                    </form>
                    @foreach ($accounts as $account)
                        <form method="POST" action="{{ route('program.accounts.update', $account) }}"
                            class="border-top py-3">
                            @csrf @method('PUT')
                            <input name="code" value="{{ $account->code }}" maxlength="50"
                                class="form-control form-control-sm mb-2" aria-label="Kode MAK" required>
                            <input name="description" value="{{ $account->description }}"
                                class="form-control form-control-sm mb-2" aria-label="Keterangan MAK">
                            <div class="d-flex gap-2 align-items-center">
                                <select name="is_active" class="form-select form-select-sm" aria-label="Status MAK">
                                    <option value="1" @selected($account->is_active)>Aktif</option>
                                    <option value="0" @selected(!$account->is_active)>Nonaktif</option>
                                </select>
                                <button class="btn btn-sm btn-outline-primary">Simpan</button>
                            </div>
                        </form>
                    @endforeach
                    {{ $accounts->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
