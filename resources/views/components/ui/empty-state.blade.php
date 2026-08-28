@props([
    'icon' => 'inbox',
    'title' => 'Belum ada data',
    'description' => null,
])

<div class="empty-state">
    <span class="empty-state-icon" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></span>
    <strong>{{ $title }}</strong>
    @if ($description)
        <p>{{ $description }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
