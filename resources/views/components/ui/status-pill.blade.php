@props(['status', 'label' => null])

<span {{ $attributes->class(['status-pill', 'status-'.$status]) }}>
    <span class="status-pill-dot" aria-hidden="true"></span>
    {{ $label ?? \App\Support\TravelStatus::label($status) }}
</span>
