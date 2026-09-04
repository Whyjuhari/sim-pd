@php($role = auth()->user()->role)
<div class="app-sidebar-content">
    <div class="app-sidebar-heading">Ruang kerja</div>
    <nav class="nav flex-column" aria-label="Navigasi utama">
        @if ($role === \App\Models\User::ROLE_USER)
            <a class="nav-link {{ request()->routeIs('dashboard.user', 'travel-reports.*', 'realizations.*') ? 'active' : '' }}"
                href="{{ route('dashboard.user') }}"><i class="bi bi-journal-check"></i><span>Tugas Perjalanan</span>
                @if (($navigationWorkCount ?? 0) > 0)
                    <span class="nav-work-badge"
                        aria-label="{{ $navigationWorkCount }} tugas memerlukan tindakan">{{ $navigationWorkCount > 99 ? '99+' : $navigationWorkCount }}</span>
                @endif
            </a>
        @elseif($role === \App\Models\User::ROLE_VERIFIER)
            <a class="nav-link {{ request()->routeIs('dashboard.verifier', 'verifications.*') ? 'active' : '' }}"
                href="{{ route('dashboard.verifier') }}"><i class="bi bi-clipboard-check-fill"></i><span>Verifikasi
                    Realisasi</span>
                @if (($navigationWorkCount ?? 0) > 0)
                    <span class="nav-work-badge"
                        aria-label="{{ $navigationWorkCount }} pengajuan menunggu verifikasi">{{ $navigationWorkCount > 99 ? '99+' : $navigationWorkCount }}</span>
                @endif
            </a>
        @else
            <a class="nav-link {{ request()->routeIs('dashboard.*') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
            </a>

            @if ($role === \App\Models\User::ROLE_ADMIN)
                <a class="nav-link {{ request()->routeIs('employees.index', 'employees.show') ? 'active' : '' }}"
                    href="{{ route('employees.index') }}"><i class="bi bi-people-fill"></i><span>Pegawai</span></a>
                <a class="nav-link {{ request()->routeIs('employees.roles') ? 'active' : '' }}"
                    href="{{ route('employees.roles') }}"><i class="bi bi-person-badge-fill"></i><span>Role
                        Operasional</span></a>
                <a class="nav-link {{ request()->routeIs('employees.create', 'employees.edit') ? 'active' : '' }}"
                    href="{{ route('employees.create') }}"><i class="bi bi-person-plus-fill"></i><span>Tambah
                        Pengguna</span></a>
                <a class="nav-link {{ request()->routeIs('admin.system-health') ? 'active' : '' }}"
                    href="{{ route('admin.system-health') }}"><i class="bi bi-heart-pulse-fill"></i><span>Kesehatan
                        Sistem</span></a>
            @elseif($role === \App\Models\User::ROLE_OFFICER)
                <a class="nav-link {{ request()->routeIs('travel-orders.*') ? 'active' : '' }}"
                    href="{{ route('travel-orders.create') }}"><i class="bi bi-file-earmark-plus-fill"></i><span>Buat
                        SPT</span></a>
                <a class="nav-link {{ request()->routeIs('spt-templates.*') ? 'active' : '' }}"
                    href="{{ route('spt-templates.index') }}"><i class="bi bi-file-earmark-word-fill"></i><span>Template
                        SPT</span></a>
            @elseif($role === \App\Models\User::ROLE_PROGRAM)
                <a class="nav-link {{ request()->routeIs('program.budget.*', 'program.tariffs.*', 'program.accounts.*', 'program.daily-allowances.*') ? 'active' : '' }}"
                    href="{{ route('program.budget.edit') }}"><i class="bi bi-sliders2-vertical"></i><span>Master
                        Anggaran</span></a>
            @endif
        @endif
    </nav>

    <div class="app-sidebar-heading mt-4">Akun</div>
    <nav class="nav flex-column" aria-label="Navigasi akun">
        <a class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}"
            href="{{ route('profile.edit') }}"><i class="bi bi-person-gear"></i><span>Profil</span></a>
    </nav>
</div>

<div class="app-sidebar-footer">
    <i class="bi bi-shield-check"></i>
    <span><strong>Sistem Internal</strong><small>BPVP Pangkep</small></span>
</div>
