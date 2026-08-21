@php($role = auth()->user()->role)
<nav class="nav flex-column" aria-label="Navigasi utama">
    <a class="nav-link {{ request()->routeIs('dashboard.*') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
    @if($role === \App\Models\User::ROLE_ADMIN)
        <a class="nav-link {{ request()->routeIs('employees.index', 'employees.show') ? 'active' : '' }}" href="{{ route('employees.index') }}"><i class="bi bi-people me-2"></i> Pegawai</a>
        <a class="nav-link {{ request()->routeIs('employees.roles') ? 'active' : '' }}" href="{{ route('employees.roles') }}"><i class="bi bi-person-badge me-2"></i> Role Operasional</a>
        <a class="nav-link {{ request()->routeIs('employees.create', 'employees.edit') ? 'active' : '' }}" href="{{ route('employees.create') }}"><i class="bi bi-person-plus me-2"></i> Tambah Pengguna</a>
        <a class="nav-link {{ request()->routeIs('admin.system-health') ? 'active' : '' }}" href="{{ route('admin.system-health') }}"><i class="bi bi-heart-pulse me-2"></i> Kesehatan Sistem</a>
    @elseif($role === \App\Models\User::ROLE_OFFICER)
        <a class="nav-link {{ request()->routeIs('travel-orders.create') ? 'active' : '' }}" href="{{ route('travel-orders.create') }}"><i class="bi bi-file-earmark-plus me-2"></i> Buat SPT</a>
    @elseif($role === \App\Models\User::ROLE_PROGRAM)
        <a class="nav-link {{ request()->routeIs('program.budget.*', 'program.tariffs.*', 'program.accounts.*') ? 'active' : '' }}" href="{{ route('program.budget.edit') }}"><i class="bi bi-sliders me-2"></i> Master Anggaran</a>
    @elseif($role === \App\Models\User::ROLE_USER)
        <a class="nav-link {{ request()->routeIs('travel-reports.*', 'realizations.*') ? 'active' : '' }}" href="{{ route('dashboard.user') }}"><i class="bi bi-journal-check me-2"></i> Tugas Perjalanan</a>
    @elseif($role === \App\Models\User::ROLE_VERIFIER)
        <a class="nav-link {{ request()->routeIs('verifications.*') ? 'active' : '' }}" href="{{ route('dashboard.verifier') }}"><i class="bi bi-clipboard-check me-2"></i> Verifikasi</a>
    @endif
    <hr>
    <a class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}" href="{{ route('profile.edit') }}"><i class="bi bi-person-gear me-2"></i> Profil & Keamanan</a>
</nav>
