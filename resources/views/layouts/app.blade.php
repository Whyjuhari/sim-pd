<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIM-PD BPVP Pangkep')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
@php
    $roleLabels = [
        \App\Models\User::ROLE_ADMIN => 'Administrator',
        \App\Models\User::ROLE_OFFICER => 'Pengelola SPT',
        \App\Models\User::ROLE_PROGRAM => 'Program & Anggaran',
        \App\Models\User::ROLE_USER => 'Pegawai',
        \App\Models\User::ROLE_VERIFIER => 'Verifikator',
        \App\Models\User::ROLE_HEAD => 'Pimpinan',
    ];
    $roleLabel = $roleLabels[auth()->user()->role] ?? 'Pengguna';
    $pageTitle = trim($__env->yieldContent('page-title')) ?: trim($__env->yieldContent('brand')) ?: 'SIM-PD';
    $pageSubtitle = trim($__env->yieldContent('page-subtitle'));
    $isDashboard = request()->routeIs('dashboard.*');
@endphp

<body class="app-body {{ $isDashboard ? 'app-dashboard' : '' }}"
    @if (session('success')) data-flash-success="{{ session('success') }}" @endif>
    <nav class="navbar app-navbar sticky-top">
        <div class="container-fluid app-navbar-inner">
            <button class="btn app-menu-toggle d-xl-none d-inline-flex align-items-center justify-content-center"
                type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNavigation"
                aria-controls="mobileNavigation" aria-label="Buka navigasi">
                <i class="bi bi-list fs-4"></i>
            </button>

            <a class="navbar-brand app-brand me-auto" href="{{ route('dashboard') }}">
                <span class="app-logo-wrap"><img src="{{ asset('assets/images/logo.png') }}" class="app-logo"
                        alt="Logo BPVP Pangkep"></span>
                <span class="app-brand-copy"><strong>SIM-PD</strong><small>BPVP Pangkep</small></span>
            </a>

            <div class="app-user-tools">
                <a href="{{ route('profile.edit') }}" class="app-user-summary">
                    <img src="{{ auth()->user()->photoUrl() }}" class="app-user-avatar"
                        alt="Foto {{ auth()->user()->nama_lengkap }}">
                    <span
                        class="app-user-copy d-none d-md-flex"><strong>{{ auth()->user()->nama_lengkap }}</strong><small>{{ $roleLabel }}</small></span>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button class="btn app-logout-button" type="submit" title="Keluar dari aplikasi"
                        aria-label="Keluar dari aplikasi"><i class="bi bi-box-arrow-right"></i><span
                            class="d-none d-sm-inline">Keluar</span></button>
                </form>
            </div>
        </div>
    </nav>

    <div class="offcanvas offcanvas-start app-navigation-drawer" tabindex="-1" id="mobileNavigation"
        aria-labelledby="mobileNavigationLabel">
        <div class="offcanvas-header">
            <div>
                <div class="app-drawer-eyebrow">SIM-PD BPVP Pangkep</div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"
                aria-label="Tutup"></button>
        </div>
        <div class="offcanvas-body app-sidebar">@include('layouts.sidebar')</div>
    </div>

    <div class="app-shell">
        <aside class="d-none d-xl-flex app-sidebar app-sidebar-desktop">@include('layouts.sidebar')</aside>
        <main class="app-main">
            <div class="app-content">
                <x-ui.page-header :title="$pageTitle" :subtitle="!$isDashboard && $pageSubtitle !== '' ? $pageSubtitle : null" :show-breadcrumb="!$isDashboard">
                    @yield('page-actions')
                </x-ui.page-header>

                @if ($errors->any())
                    <div class="alert alert-danger app-alert" role="alert"><strong><i
                                class="bi bi-exclamation-octagon-fill me-2"></i>Periksa kembali data berikut:</strong>
                        <ul class="mb-0 mt-2">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>
    @stack('scripts')
</body>

</html>
