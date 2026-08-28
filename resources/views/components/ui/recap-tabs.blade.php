@props(['recaps', 'idPrefix' => 'recap'])

@php
    $sections = [
        'months' => ['label' => 'Bulanan', 'icon' => 'calendar3'],
        'destinations' => ['label' => 'Tujuan', 'icon' => 'geo-alt'],
        'accounts' => ['label' => 'MAK', 'icon' => 'wallet2'],
    ];
@endphp

<div class="card mb-4">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <h2 class="section-title"><span class="section-title-icon"><i class="bi bi-bar-chart-line-fill"></i></span> Rekap Terstruktur</h2>
            <ul class="nav nav-pills recap-tabs" role="tablist">
                @foreach($sections as $key => $section)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $loop->first ? 'active' : '' }}" id="{{ $idPrefix }}-{{ $key }}-tab"
                            data-bs-toggle="tab" data-bs-target="#{{ $idPrefix }}-{{ $key }}" type="button" role="tab"
                            aria-controls="{{ $idPrefix }}-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                            <i class="bi bi-{{ $section['icon'] }}"></i> {{ $section['label'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="card-body">
        <div class="tab-content">
            @foreach($sections as $key => $section)
                <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="{{ $idPrefix }}-{{ $key }}"
                    role="tabpanel" aria-labelledby="{{ $idPrefix }}-{{ $key }}-tab" tabindex="0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>{{ $section['label'] }}</th><th>Penugasan</th><th>Estimasi</th><th>Realisasi</th><th>Selisih</th></tr></thead>
                            <tbody>
                                @forelse($recaps[$key] as $row)
                                    <tr>
                                        <td class="fw-semibold">{{ $row['label'] }}</td>
                                        <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                                        <td>Rp {{ number_format($row['estimate'], 0, ',', '.') }}</td>
                                        <td class="text-success fw-semibold">Rp {{ number_format($row['realized'], 0, ',', '.') }}</td>
                                        <td>Rp {{ number_format($row['difference'], 0, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><x-ui.empty-state icon="bar-chart" title="Belum ada data rekap" description="Data akan muncul sesuai periode dan filter yang dipilih." /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
