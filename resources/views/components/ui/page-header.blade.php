@props([
    'title',
    'subtitle' => null,
    'showBreadcrumb' => true,
])

<header class="app-page-header">
    <div class="app-page-copy">
        @if ($showBreadcrumb)
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $title }}</li>
                </ol>
            </nav>
        @endif

        <h1>{{ $title }}</h1>

        @if ($subtitle)
            <p>{{ $subtitle }}</p>
        @endif
    </div>

    @if (trim((string) $slot) !== '')
        <div class="app-page-actions">{{ $slot }}</div>
    @endif
</header>
