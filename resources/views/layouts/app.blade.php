<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SIM-PD BPVP Pangkep')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <nav class="navbar navbar-dark bg-identity shadow-sm app-navbar">
        <div class="container-fluid px-lg-4">
            <button class="btn btn-outline-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNavigation" aria-controls="mobileNavigation" aria-label="Buka navigasi">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand fw-bold d-flex align-items-center me-auto" href="{{ route('dashboard') }}">
                <img src="{{ asset('assets/images/logo.png') }}" class="app-logo me-2" alt="Logo BPVP Pangkep">
                <span class="app-brand-text">@yield('brand', 'SIM-PD BPVP Pangkep')</span>
            </a>
            <div class="d-flex align-items-center gap-2 text-white">
                <a href="{{ route('profile.edit') }}" class="btn btn-link text-white text-decoration-none p-1 d-none d-md-inline">
                    <i class="bi bi-person-circle"></i> {{ auth()->user()->nama_lengkap }}
                </a>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button class="btn btn-outline-light btn-sm" type="submit"><i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Logout</span></button>
                </form>
            </div>
        </div>
    </nav>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNavigation" aria-labelledby="mobileNavigationLabel">
        <div class="offcanvas-header"><h5 class="offcanvas-title" id="mobileNavigationLabel">Menu SIM-PD</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button></div>
        <div class="offcanvas-body app-sidebar">@include('layouts.sidebar')</div>
    </div>

    <div class="container-fluid">
        <div class="row">
            <aside class="col-lg-2 d-none d-lg-block app-sidebar">@include('layouts.sidebar')</aside>
            <main class="col-lg-10 app-main px-3 px-lg-4 py-4">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-3">
                        <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">@yield('brand', 'SIM-PD')</li>
                    </ol>
                </nav>

                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>
                @endif
                @if($errors->any())
                    <div class="alert alert-danger" role="alert"><strong>Periksa kembali data berikut:</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
    @stack('scripts')
</body>
</html>
