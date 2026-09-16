@props([
    'label',
    'value',
    'icon' => 'bar-chart',
    'tone' => 'primary',
    'hint' => null,
    'href' => null,
    'active' => false,
    'liveFilter' => false,
])

@if ($href)
<a href="{{ $href }}"
    class="metric-card metric-card-{{ $tone }} metric-card-link {{ $active ? 'is-active' : '' }} h-100"
    @if ($active) aria-current="true" @endif
    @if ($liveFilter) data-live-filter-link @endif>
    <div class="metric-card-body">
        <div class="metric-card-copy">
            <span class="metric-card-label">{{ $label }}</span>
            <strong class="metric-card-value">{{ $value }}</strong>
            @if ($hint)
                <span class="metric-card-hint">{{ $hint }}</span>
            @endif
        </div>
        <span class="metric-card-icon" aria-hidden="true">
            <i class="bi bi-{{ $icon }}"></i>
        </span>
    </div>
</a>
@else
    <div class="metric-card metric-card-{{ $tone }} h-100">
        <div class="metric-card-body">
            <div class="metric-card-copy">
                <span class="metric-card-label">{{ $label }}</span>
                <strong class="metric-card-value">{{ $value }}</strong>
                @if ($hint)
                    <span class="metric-card-hint">{{ $hint }}</span>
                @endif
            </div>
            <span class="metric-card-icon" aria-hidden="true">
                <i class="bi bi-{{ $icon }}"></i>
            </span>
        </div>
    </div>
@endif
