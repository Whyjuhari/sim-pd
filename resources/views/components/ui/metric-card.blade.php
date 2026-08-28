@props([
    'label',
    'value',
    'icon' => 'bar-chart',
    'tone' => 'primary',
    'hint' => null,
])

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
