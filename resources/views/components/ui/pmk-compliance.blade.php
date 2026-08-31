@props(['summary', 'title' => 'Kepatuhan PMK'])

<section class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
        <h2 class="section-title mb-0"><span class="section-title-icon"><i class="bi bi-shield-check"></i></span>{{ $title }}</h2>
        @if(($summary['within'] + $summary['over']) > 0)
            <span class="badge rounded-pill text-bg-{{ $summary['over'] > 0 ? 'warning' : 'success' }}">{{ $summary['compliance_rate'] }}%</span>
        @endif
    </div>
    <div class="card-body">
        <div class="row g-3 text-center">
            @foreach([
                ['Dalam patokan', $summary['within'], 'success'],
                ['Di atas patokan', $summary['over'], 'warning'],
                ['Legacy', $summary['legacy'], 'secondary'],
                ['Tanpa patokan', $summary['unavailable'], 'danger'],
            ] as [$label, $value, $tone])
                <div class="col-6 col-lg-3">
                    <div class="pmk-summary-item">
                        <strong class="text-{{ $tone }}">{{ $value }}</strong>
                        <span>{{ $label }}</span>
                    </div>
                </div>
            @endforeach
        </div>
        @if($summary['over_amount'] > 0)
            <div class="small text-muted text-center mt-3">Selisih di atas patokan Rp {{ number_format($summary['over_amount'], 0, ',', '.') }}</div>
        @endif
    </div>
</section>
