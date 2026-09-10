@extends('layouts.app')
@section('title', 'Realisasi Biaya - SIM-PD')
@section('brand',
    $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED
    ? 'Perbaiki Realisasi'
    : 'Realisasi
    Biaya')
@section('page-subtitle', 'Isi rincian biaya aktual sesuai jenis angkutan dan bukti transaksi.')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <div class="workflow-steps mb-4 d-flex flex-sm-column" aria-label="Tahapan perjalanan dinas">
                <span class="done">1. SPT</span>
                <span class="done">2. Laporan</span>
                <span class="active">3. Petinjau Laporan</span>
                <span class="active">4. Realisasi</span>
                <span>5. Verifikasi</span>
                <span>6. Selesai</span>
            </div>

            @if ($travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED)
                <div class="alert alert-danger">
                    <h6 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Realisasi perlu diperbaiki</h6>
                    <p class="mb-0">
                        {{ $travel->catatan_verifikator ?: 'Silakan periksa kembali nominal dan bukti realisasi.' }}</p>
                </div>
            @endif

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <div class="small text-muted">Surat Perintah Tugas</div>
                            <div class="fw-semibold text-identity">{{ $travel->sptOperationalReference() }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted">Tujuan</div>
                            <div class="fw-semibold"><i class="bi bi-geo-alt-fill text-danger"></i>
                                {{ $travel->kota_tujuan }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Jenis Angkutan</div>
                            <div class="fw-semibold"><i
                                    class="bi bi-{{ $travel->angkutan === 'Pesawat Udara' ? 'airplane' : 'car-front' }} me-1"></i>{{ $travel->angkutan }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form id="realizationForm" action="{{ route('realizations.store') }}" method="POST"
                enctype="multipart/form-data" data-realization-preview
                data-daily-allowance="{{ (float) $recommendation['daily_allowance_total'] }}">
                @csrf
                <input type="hidden" name="id" value="{{ $travel->id }}">
                <div id="deletedDetails">
                    @foreach (array_map('intval', old('hapus_rincian', [])) as $deletedDetailId)
                        <input type="hidden" name="hapus_rincian[]" value="{{ $deletedDetailId }}">
                    @endforeach
                </div>

                @if ($errors->has('items'))
                    <div class="alert alert-danger">{{ $errors->first('items') }}</div>
                @endif

                @foreach ([\App\Models\RealisasiRincian::CATEGORY_HOTEL => 'Penginapan', \App\Models\RealisasiRincian::CATEGORY_TRANSPORT => 'Transportasi'] as $category => $label)
                    @php($categoryItems = collect($items)->where('category', $category))
                    @if ($categoryItems->isNotEmpty())
                        <section class="card shadow-sm mb-4 realization-group" data-category-group="{{ $category }}">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 py-3">
                                <h2 class="section-title mb-0">
                                    <span class="section-title-icon"><i
                                            class="bi bi-{{ $category === 'hotel' ? 'building' : 'signpost-2' }}"></i></span>
                                    {{ $label }}
                                </h2>
                                <span class="badge text-bg-light border">Maks. 3 bukti per rincian</span>
                            </div>
                            <div class="card-body p-3 p-md-4 realization-items" id="{{ $category }}Items">
                                @if ($category === \App\Models\RealisasiRincian::CATEGORY_HOTEL)
                                    <div class="alert alert-light border mb-3">
                                        <div class="fw-semibold"><i class="bi bi-building-check me-1"></i>
                                            Batas penginapan Rp
                                            {{ number_format($recommendation['hotel_limit'], 0, ',', '.') }}
                                        </div>
                                        <div class="small text-muted">
                                            Rp {{ number_format($recommendation['hotel_per_day'], 0, ',', '.') }} per malam
                                            × {{ $recommendation['hotel_nights'] }} malam
                                            @if ($recommendation['hotel_rate_source'] === 'pmk')
                                                · PMK 32/2025 Eselon IV/Golongan III/II/I
                                            @else
                                                · Tarif legacy
                                            @endif
                                        </div>
                                    </div>
                                @endif
                                @if (
                                    $category === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT &&
                                        ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground')
                                    <div class="alert alert-info border mb-3">
                                        <div class="fw-semibold"><i class="bi bi-signpost-split me-1"></i>
                                            Patokan PMK pergi-pulang Rp
                                            {{ number_format($recommendation['transport_limit'], 0, ',', '.') }}
                                        </div>
                                        <div class="small">
                                            Rp
                                            {{ number_format($recommendation['ground_transport_one_way'], 0, ',', '.') }}
                                            per perjalanan satu arah ·
                                            {{ $recommendation['ground_transport_origin'] }} ↔
                                            {{ $recommendation['ground_transport_destination'] }}.
                                            Tarif ini merupakan patokan yang dapat dilampaui dengan bukti, bukan batas
                                            pemotongan otomatis.
                                        </div>
                                    </div>
                                @endif
                                @if (
                                    $category === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT &&
                                        ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_air')
                                    <div class="alert alert-info border mb-3">
                                        <div class="fw-semibold"><i class="bi bi-airplane me-1"></i> Patokan transportasi
                                            udara PMK</div>
                                        <div class="small">Setiap komponen mempunyai patokan sendiri dan tidak dibagi rata.
                                            Nilai aktual dapat melampaui patokan dengan alasan dan bukti yang sesuai.</div>
                                    </div>
                                @endif
                                @foreach ($categoryItems as $item)
                                    @php($key = $item['key'])
                                    @php($isAirBenchmark = ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_air' && $item['category'] === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT && $item['is_preset'])
                                    @php($isTerminalBenchmark = $isAirBenchmark && in_array($item['code'], [\App\Models\RealisasiRincian::CODE_LOCAL_DEPARTURE, \App\Models\RealisasiRincian::CODE_LOCAL_RETURN, \App\Models\RealisasiRincian::CODE_DESTINATION_OUTBOUND, \App\Models\RealisasiRincian::CODE_DESTINATION_RETURN], true))
                                    <article class="realization-detail-card" data-realization-item
                                        data-category="{{ $item['category'] }}" data-key="{{ $key }}"
                                        @if ($isAirBenchmark) data-air-benchmark="1" data-benchmark-amount="{{ $item['benchmark_amount'] ?? 0 }}" data-benchmark-source="{{ $item['benchmark_source'] ?? 'unavailable' }}" data-terminal-benchmark="{{ $isTerminalBenchmark ? '1' : '0' }}" @endif>
                                        <input type="hidden" name="items[{{ $key }}][id]"
                                            value="{{ $item['id'] }}">
                                        <input type="hidden" name="items[{{ $key }}][code]"
                                            value="{{ $item['code'] }}">

                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                            <div class="flex-grow-1">
                                                <label for="description-{{ $key }}"
                                                    class="form-label fw-semibold">Uraian Biaya</label>
                                                <input id="description-{{ $key }}" type="text"
                                                    name="items[{{ $key }}][description]"
                                                    value="{{ old("items.{$key}.description", $item['description']) }}"
                                                    maxlength="255" required
                                                    class="form-control detail-description @error("items.{$key}.description") is-invalid @enderror"
                                                    @readonly($item['is_preset'])>
                                                @error("items.{$key}.description")
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                            @if (!$item['is_preset'])
                                                <button type="button"
                                                    class="btn btn-outline-danger btn-sm remove-realization-detail mt-4"
                                                    data-detail-id="{{ $item['id'] }}"
                                                    aria-label="Hapus {{ $item['description'] }}">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            @endif
                                        </div>

                                        <div class="row g-3 align-items-start">
                                            <div class="col-md-5">
                                                <label for="amount-{{ $key }}"
                                                    class="form-label fw-semibold">Nominal Diajukan</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">Rp</span>
                                                    <input id="amount-{{ $key }}" type="number"
                                                        name="items[{{ $key }}][amount]" min="0"
                                                        step="1"
                                                        value="{{ old("items.{$key}.amount", $item['amount']) }}"
                                                        class="form-control detail-amount @error("items.{$key}.amount") is-invalid @enderror"
                                                        required>
                                                </div>
                                                @error("items.{$key}.amount")
                                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                                @enderror
                                                @if (
                                                    ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground' &&
                                                        in_array(
                                                            $item['code'],
                                                            [\App\Models\RealisasiRincian::CODE_GROUND_OUTBOUND, \App\Models\RealisasiRincian::CODE_GROUND_RETURN],
                                                            true))
                                                    <div class="form-text">
                                                        Patokan satu arah Rp
                                                        {{ number_format($recommendation['ground_transport_one_way'], 0, ',', '.') }}.
                                                    </div>
                                                @elseif(
                                                    ($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground' &&
                                                        $item['category'] === \App\Models\RealisasiRincian::CATEGORY_TRANSPORT &&
                                                        !$item['is_preset']
                                                )
                                                    <div class="form-text text-warning-emphasis">
                                                        Rincian tambahan tidak mempunyai pasangan tarif PMK; nilai sistem
                                                        Rp0.
                                                    </div>
                                                @elseif($isAirBenchmark)
                                                    <div class="form-text">
                                                        @if (($item['benchmark_source'] ?? null) === 'pmk')
                                                            Patokan PMK Rp
                                                            {{ number_format($item['benchmark_amount'] ?? 0, 0, ',', '.') }}.
                                                        @elseif(($item['benchmark_source'] ?? null) === 'legacy')
                                                            Tarif PMK tidak tersedia · estimasi legacy Rp
                                                            {{ number_format($item['benchmark_amount'] ?? 0, 0, ',', '.') }}.
                                                        @else
                                                            Tarif PMK tidak tersedia; nilai aktual wajib dijelaskan.
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="col-md-7">
                                                <label for="evidence-{{ $key }}"
                                                    class="form-label fw-semibold">Bukti Biaya</label>
                                                @if (collect($item['evidence'])->isNotEmpty())
                                                    <x-ui.evidence-list :items="$item['evidence']" :removable="true" />
                                                @endif
                                                <input id="evidence-{{ $key }}" type="file"
                                                    name="items[{{ $key }}][evidence][]" multiple
                                                    accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                                                    class="form-control detail-evidence mt-2 {{ $errors->has("items.{$key}.evidence") || $errors->has("items.{$key}.evidence.*") ? 'is-invalid' : '' }}">
                                                <div class="form-text">JPG, PNG, atau PDF · maksimal 3 file · masing-masing
                                                    5 MB.</div>
                                                @if ($errors->has("items.{$key}.evidence") || $errors->has("items.{$key}.evidence.*"))
                                                    <div class="invalid-feedback d-block">
                                                        {{ $errors->first("items.{$key}.evidence") ?: $errors->first("items.{$key}.evidence.*") }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($isAirBenchmark)
                                            <div class="air-overrun-fields mt-3" data-air-overrun-fields hidden>
                                                <div class="alert alert-warning py-2 small mb-3"><i
                                                        class="bi bi-exclamation-triangle me-1"></i> Nominal melampaui
                                                    patokan. Jelaskan kebutuhan biaya aktual ini.</div>
                                                <label for="overrun-reason-{{ $key }}"
                                                    class="form-label fw-semibold">Alasan melampaui patokan</label>
                                                <textarea id="overrun-reason-{{ $key }}" name="items[{{ $key }}][overrun_reason]" rows="2"
                                                    maxlength="2000" class="form-control @error("items.{$key}.overrun_reason") is-invalid @enderror">{{ old("items.{$key}.overrun_reason", $item['overrun_reason'] ?? '') }}</textarea>
                                                @error("items.{$key}.overrun_reason")
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                                @if ($isTerminalBenchmark && ($item['benchmark_source'] ?? null) === 'pmk')
                                                    <div class="form-check mt-3">
                                                        <input type="hidden"
                                                            name="items[{{ $key }}][office_route_confirmed]"
                                                            value="0">
                                                        <input id="office-route-{{ $key }}"
                                                            class="form-check-input" type="checkbox"
                                                            name="items[{{ $key }}][office_route_confirmed]"
                                                            value="1" @checked(old("items.{$key}.office_route_confirmed", $item['office_route_confirmed'] ?? false))>
                                                        <label class="form-check-label"
                                                            for="office-route-{{ $key }}">Perjalanan dilakukan
                                                            dari/ke kantor.</label>
                                                    </div>
                                                    <div class="form-check mt-2">
                                                        <input type="hidden"
                                                            name="items[{{ $key }}][non_private_vehicle_confirmed]"
                                                            value="0">
                                                        <input id="non-private-{{ $key }}"
                                                            class="form-check-input" type="checkbox"
                                                            name="items[{{ $key }}][non_private_vehicle_confirmed]"
                                                            value="1" @checked(old("items.{$key}.non_private_vehicle_confirmed", $item['non_private_vehicle_confirmed'] ?? false))>
                                                        <label class="form-check-label"
                                                            for="non-private-{{ $key }}">Tidak menggunakan
                                                            kendaraan pribadi.</label>
                                                    </div>
                                                    @error("items.{$key}.office_route_confirmed")
                                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                                    @enderror
                                                @endif
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach

                <div class="card shadow-sm mb-4">
                    <div
                        class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                        <div>
                            <h2 class="h6 mb-1">Transportasi lainnya</h2>
                            <p class="small text-muted mb-0">
                                Tambahkan hanya biaya transportasi sah yang belum tersedia pada rincian utama.
                                @if (in_array($recommendation['transport_rate_source'] ?? 'legacy', ['pmk_ground', 'pmk_air'], true))
                                    Rincian tambahan tetap dicatat, tetapi nilai disetujui sistem Rp0 karena tidak mempunyai
                                    pasangan tarif PMK.
                                @endif
                            </p>
                        </div>
                        <button type="button" id="addCustomTransport" class="btn btn-outline-primary">
                            <i class="bi bi-plus-circle"></i> Tambah Rincian
                        </button>
                    </div>
                </div>

                <div class="card shadow-sm mb-4 realization-summary-card">
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-6 col-lg-3"><span class="small text-muted d-block">Hotel</span><strong
                                    id="hotelSubtotal">Rp 0</strong></div>
                            <div class="col-6 col-lg-3"><span class="small text-muted d-block">Transportasi</span><strong
                                    id="transportSubtotal">Rp 0</strong></div>
                            <div class="col-6 col-lg-3"><span class="small text-muted d-block">Uang
                                    Harian</span><strong>Rp
                                    {{ number_format($recommendation['daily_allowance_total'], 0, ',', '.') }}</strong>
                            </div>
                            <div class="col-6 col-lg-3"><span class="small text-muted d-block">Total
                                    Pengajuan</span><strong id="claimTotal" class="text-identity">Rp 0</strong></div>
                        </div>
                    </div>
                </div>

                <div class="form-action-bar">
                    <a href="{{ route('dashboard.user') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Kembali ke Halaman Utama
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitRealization">
                        <i class="bi bi-eye"></i>
                        {{ $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED ? 'Tinjau & Kirim Ulang' : 'Tinjau & Kirim' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <template id="customTransportTemplate">
        <article class="realization-detail-card" data-realization-item data-category="transportasi" data-key="__KEY__">
            <input type="hidden" name="items[__KEY__][id]" value="">
            <input type="hidden" name="items[__KEY__][code]" value="">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div class="flex-grow-1">
                    <label for="description-__KEY__" class="form-label fw-semibold">Uraian Biaya</label>
                    <input id="description-__KEY__" type="text" name="items[__KEY__][description]" maxlength="255"
                        class="form-control detail-description"
                        placeholder="Contoh: transportasi terminal ke lokasi kegiatan" required>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm remove-realization-detail mt-4"
                    aria-label="Hapus rincian">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="amount-__KEY__" class="form-label fw-semibold">Nominal Diajukan</label>
                    <div class="input-group"><span class="input-group-text">Rp</span>
                        <input id="amount-__KEY__" type="number" name="items[__KEY__][amount]" min="1"
                            step="1" value="0" class="form-control detail-amount" required>
                    </div>
                    @if (($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_ground')
                        <div class="form-text text-warning-emphasis">Tidak mempunyai pasangan tarif PMK; nilai sistem Rp0.
                        </div>
                    @elseif(($recommendation['transport_rate_source'] ?? 'legacy') === 'pmk_air')
                        <div class="form-text text-warning-emphasis">Tidak mempunyai patokan PMK. Nilai aktual tetap
                            diperiksa dari bukti.</div>
                    @endif
                </div>
                <div class="col-md-7">
                    <label for="evidence-__KEY__" class="form-label fw-semibold">Bukti Biaya</label>
                    <input id="evidence-__KEY__" type="file" name="items[__KEY__][evidence][]" multiple
                        accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        class="form-control detail-evidence">
                    <div class="form-text">JPG, PNG, atau PDF · maksimal 3 file · masing-masing 5 MB.</div>
                </div>
            </div>
        </article>
    </template>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('realizationForm');
            if (!form) return;

            const transportContainer = document.getElementById('transportasiItems');
            const template = document.getElementById('customTransportTemplate');
            const deletedDetails = document.getElementById('deletedDetails');
            const dailyAllowance = Number(form.dataset.dailyAllowance || 0);
            let customCounter = document.querySelectorAll(
                    '[data-realization-item][data-category="transportasi"] .detail-description:not([readonly])')
                .length;
            let previewConfirmed = false;

            const rupiah = value => new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                maximumFractionDigits: 0
            }).format(value || 0);
            const rows = () => Array.from(form.querySelectorAll('[data-realization-item]'));
            const isImageEvidence = (mime, name) => String(mime || '').startsWith('image/') || /\.(?:jpe?g|png)$/i
                .test(name || '');

            const updateAirOverrun = row => {
                if (row.dataset.airBenchmark !== '1') return;
                const amount = Number(row.querySelector('.detail-amount')?.value || 0);
                const benchmark = Number(row.dataset.benchmarkAmount || 0);
                const exceeds = amount > benchmark;
                const fields = row.querySelector('[data-air-overrun-fields]');
                if (!fields) return;
                fields.hidden = !exceeds;
                const reason = fields.querySelector('textarea');
                if (reason) reason.required = exceeds;
                fields.querySelectorAll('input[type="checkbox"]').forEach(input => input.required = exceeds);
            };

            const buildEvidenceCard = ({
                name,
                mime,
                url,
                origin
            }) => {
                const link = document.createElement('a');
                link.className = 'realization-preview-evidence-card';
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.title = `Buka bukti ${name}`;

                const visual = document.createElement('span');
                visual.className = 'realization-preview-evidence-visual';
                if (isImageEvidence(mime, name)) {
                    const image = document.createElement('img');
                    image.src = url;
                    image.alt = `Bukti ${name}`;
                    visual.appendChild(image);
                } else {
                    const icon = document.createElement('i');
                    icon.className = 'bi bi-file-earmark-pdf-fill';
                    icon.setAttribute('aria-hidden', 'true');
                    visual.appendChild(icon);
                }

                const fileName = document.createElement('span');
                fileName.className = 'realization-preview-evidence-name';
                fileName.textContent = name;

                const meta = document.createElement('small');
                meta.textContent = `${isImageEvidence(mime, name) ? 'Gambar' : 'PDF'} · ${origin}`;

                link.append(visual, fileName, meta);
                return link;
            };

            const updateSummary = () => {
                const totals = {
                    hotel: 0,
                    transportasi: 0
                };
                rows().forEach(row => {
                    totals[row.dataset.category] += Number(row.querySelector('.detail-amount')?.value ||
                        0);
                });
                document.getElementById('hotelSubtotal').textContent = rupiah(totals.hotel);
                document.getElementById('transportSubtotal').textContent = rupiah(totals.transportasi);
                document.getElementById('claimTotal').textContent = rupiah(totals.hotel + totals.transportasi +
                    dailyAllowance);
                return totals;
            };

            const bindRemove = button => button.addEventListener('click', () => {
                const row = button.closest('[data-realization-item]');
                const detailId = button.dataset.detailId;
                if (detailId) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'hapus_rincian[]';
                    input.value = detailId;
                    deletedDetails.appendChild(input);
                }
                row.remove();
                updateSummary();
            });

            document.querySelectorAll('.remove-realization-detail').forEach(bindRemove);
            form.addEventListener('input', event => {
                if (event.target.matches('.detail-amount')) {
                    updateAirOverrun(event.target.closest('[data-realization-item]'));
                    updateSummary();
                }
            });

            document.getElementById('addCustomTransport')?.addEventListener('click', () => {
                const customCount = form.querySelectorAll(
                    '[data-realization-item][data-category="transportasi"] .detail-description:not([readonly])'
                ).length;
                if (!transportContainer || !template || customCount >= 10) {
                    window.SimPdDialog?.warning('Maksimal sepuluh rincian transportasi tambahan.',
                        'Batas rincian');
                    return;
                }
                customCounter += 1;
                const key = `custom_${Date.now()}_${customCounter}`;
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__KEY__', key).trim();
                const row = wrapper.firstElementChild;
                transportContainer.appendChild(row);
                bindRemove(row.querySelector('.remove-realization-detail'));
                row.querySelector('.detail-description')?.focus();
                updateSummary();
            });

            form.addEventListener('submit', async event => {
                if (previewConfirmed) return;
                event.preventDefault();
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                const submitter = event.submitter;
                const totals = updateSummary();
                const content = document.createElement('div');
                content.className = 'realization-preview text-start';
                const objectUrls = [];

                rows().forEach(row => {
                    const line = document.createElement('div');
                    line.className = 'realization-preview-line';
                    const name = document.createElement('span');
                    name.textContent = row.querySelector('.detail-description')?.value ||
                        'Rincian biaya';
                    const amount = document.createElement('strong');
                    amount.textContent = rupiah(Number(row.querySelector('.detail-amount')
                        ?.value || 0));
                    line.append(name, amount);

                    if (row.dataset.airBenchmark === '1') {
                        const benchmark = Number(row.dataset.benchmarkAmount || 0);
                        const claimed = Number(row.querySelector('.detail-amount')?.value || 0);
                        const benchmarkText = document.createElement('small');
                        benchmarkText.className = claimed > benchmark ?
                            'text-warning-emphasis' : 'text-success';
                        benchmarkText.textContent = claimed > benchmark ?
                            `Melebihi patokan ${rupiah(benchmark)} sebesar ${rupiah(claimed - benchmark)}` :
                            `Dalam patokan ${rupiah(benchmark)}`;
                        line.appendChild(benchmarkText);
                        if (claimed > benchmark) {
                            const reasonText = row.querySelector('[name$="[overrun_reason]"]')
                                ?.value?.trim();
                            if (reasonText) {
                                const reason = document.createElement('small');
                                reason.textContent = `Alasan: ${reasonText}`;
                                line.appendChild(reason);
                            }
                        }
                    }

                    const files = Array.from(row.querySelector('.detail-evidence')?.files ||
                []);
                    const existingEvidence = Array.from(row.querySelectorAll(
                            '[data-existing-evidence]'))
                        .filter(item => !item.querySelector('input[name="hapus_bukti[]"]')
                            ?.checked)
                        .map(item => ({
                            name: item.dataset.evidenceName || 'Bukti tersimpan',
                            mime: item.dataset.evidenceMime || '',
                            url: item.dataset.evidenceUrl || '#',
                            origin: 'Tersimpan',
                        }));
                    const newEvidence = files.map(file => {
                        const url = URL.createObjectURL(file);
                        objectUrls.push(url);
                        return {
                            name: file.name,
                            mime: file.type,
                            url,
                            origin: 'Baru'
                        };
                    });
                    const evidence = [...existingEvidence, ...newEvidence];

                    if (evidence.length) {
                        const evidenceTitle = document.createElement('small');
                        evidenceTitle.className = 'realization-preview-evidence-title';
                        evidenceTitle.textContent = `Bukti biaya (${evidence.length})`;

                        const evidenceGrid = document.createElement('div');
                        evidenceGrid.className = 'realization-preview-evidence-grid';
                        evidence.forEach(item => evidenceGrid.appendChild(buildEvidenceCard(
                            item)));
                        line.append(evidenceTitle, evidenceGrid);
                    }
                    content.appendChild(line);
                });

                const total = document.createElement('div');
                total.className = 'realization-preview-total';
                total.textContent =
                    `Total termasuk uang harian: ${rupiah(totals.hotel + totals.transportasi + dailyAllowance)}`;
                content.appendChild(total);

                let result;
                try {
                    result = await Swal.fire({
                        title: 'Periksa realisasi sebelum dikirim',
                        html: content,
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonText: '{{ $travel->status === \App\Models\PerjalananDinas::STATUS_REJECTED ? 'Ya, kirim ulang' : 'Ya, kirim realisasi' }}',
                        cancelButtonText: 'Periksa kembali',
                        reverseButtons: true,
                        focusCancel: true,
                        customClass: {
                            popup: 'sim-swal-popup',
                            confirmButton: 'sim-swal-confirm-primary',
                            cancelButton: 'sim-swal-cancel'
                        },
                        buttonsStyling: false,
                    });
                } finally {
                    objectUrls.forEach(url => URL.revokeObjectURL(url));
                }

                if (result?.isConfirmed) {
                    previewConfirmed = true;
                    form.requestSubmit(submitter || undefined);
                }
            });

            rows().forEach(updateAirOverrun);
            updateSummary();
        });
        const numberInputs = document.querySelectorAll('input[type="number"]');

        numberInputs.forEach(input => {
            input.addEventListener('wheel', function(e) {
                e.preventDefault();
            }, {
                passive: false
            });
        });
    </script>
@endpush
