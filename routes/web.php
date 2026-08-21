<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RealizationController;
use App\Http\Controllers\TravelOrderController;
use App\Http\Controllers\TravelReportController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\BudgetSettingController;
use App\Http\Controllers\SystemHealthController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/',
    [AuthController::class, 'showLogin']
)->name('login');

Route::post(
    '/login',
    [AuthController::class, 'login']
)->name('login.attempt');

Route::post(
    '/cek_login.php',
    [AuthController::class, 'login']
)->name('legacy.login.attempt');


Route::middleware('auth')->group(function (): void {

    Route::post(
        '/logout',
        [AuthController::class, 'logout']
    )->name('logout');

    Route::get('/profil', [ProfileController::class, 'edit'])
        ->name('profile.edit');
    Route::post('/profil', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get(
        '/dashboard',
        [DashboardController::class, 'redirect']
    )->middleware('password.changed')->name('dashboard');

    Route::get(
        '/documents/perjadin',
        [DocumentController::class, 'perjadin']
    )->middleware('password.changed')->name('documents.perjadin');

    Route::get(
        '/documents/surat-tugas',
        [DocumentController::class, 'suratTugas']
    )->middleware('password.changed')->name('documents.surat-tugas');

    Route::middleware(['password.changed', 'role:admin'])
        ->prefix('admin')
        ->group(function (): void {
            Route::get(
                '/',
                [DashboardController::class, 'admin']
            )->name('dashboard.admin');
            Route::get(
                '/pegawai/{employee}/tanda-tangan',
                [EmployeeController::class, 'signature']
            )
                ->whereNumber('employee')
                ->name('employees.signature');


            Route::delete(
                '/pegawai/{employee}/tanda-tangan',
                [EmployeeController::class, 'destroySignature']
            )->whereNumber('employee')->name('employees.signature.destroy');


            Route::get(
                '/pegawai',
                [EmployeeController::class, 'index']
            )->name('employees.index');
            Route::get(
                '/roles',
                [EmployeeController::class, 'roles']
            )->name('employees.roles');

            Route::get('/kesehatan-sistem', SystemHealthController::class)
                ->name('admin.system-health');

            Route::get(
                '/pegawai/create',
                [EmployeeController::class, 'create']
            )->name('employees.create');

            Route::post(
                '/pegawai',
                [EmployeeController::class, 'store']
            )->name('employees.store');

            Route::get(
                '/pegawai/detail',
                [EmployeeController::class, 'show']
            )->name('employees.show');

            Route::get(
                '/pegawai/edit',
                [EmployeeController::class, 'edit']
            )->name('employees.edit');

            Route::post(
                '/pegawai/update',
                [EmployeeController::class, 'update']
            )->name('employees.update');

            Route::post(
                '/pegawai/delete',
                [EmployeeController::class, 'destroy']
            )->name('employees.destroy');
        });



    Route::middleware(['password.changed', 'role:officer'])
        ->prefix('officer')
        ->group(function (): void {

            Route::get(
                '/',
                [DashboardController::class, 'officer']
            )->name('dashboard.officer');

            Route::get(
                '/spt/create',
                [TravelOrderController::class, 'create']
            )->name('travel-orders.create');

            Route::post(
                '/spt',
                [TravelOrderController::class, 'store']
            )->name('travel-orders.store');

            Route::get(
                '/spt/{sptGroupId}',
                [TravelOrderController::class, 'show']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.show');

            Route::get(
                '/spt/{sptGroupId}/edit',
                [TravelOrderController::class, 'edit']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.edit');

            Route::put(
                '/spt/{sptGroupId}',
                [TravelOrderController::class, 'update']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.update');
            Route::delete(
                '/spt/{sptGroupId}',
                [TravelOrderController::class, 'destroy']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.destroy');

            // Route::get(
            //     '/documents/surat-tugas',
            //     [DocumentController::class, 'suratTugas']
            // )->name('documents.surat-tugas');
        });

    Route::middleware(['password.changed', 'role:program'])
        ->prefix('program')
        ->group(function (): void {

            Route::get(
                '/',
                [DashboardController::class, 'program']
            )->name('dashboard.program');

            Route::get('/anggaran', [BudgetSettingController::class, 'edit'])
                ->name('program.budget.edit');
            Route::put('/anggaran/batas', [BudgetSettingController::class, 'updateSettings'])
                ->name('program.budget.settings.update');
            Route::post('/anggaran/tarif', [BudgetSettingController::class, 'storeTariff'])
                ->name('program.tariffs.store');
            Route::put('/anggaran/tarif/{tariff}', [BudgetSettingController::class, 'updateTariff'])
                ->whereNumber('tariff')
                ->name('program.tariffs.update');
            Route::delete('/anggaran/tarif/{tariff}', [BudgetSettingController::class, 'destroyTariff'])
                ->whereNumber('tariff')
                ->name('program.tariffs.destroy');
            Route::post('/anggaran/mak', [BudgetSettingController::class, 'storeAccount'])
                ->name('program.accounts.store');
            Route::put('/anggaran/mak/{account}', [BudgetSettingController::class, 'updateAccount'])
                ->whereNumber('account')
                ->name('program.accounts.update');
        });

    Route::middleware(['password.changed', 'role:head'])
        ->prefix('head')
        ->group(function (): void {

            Route::get(
                '/',
                [DashboardController::class, 'head']
            )->name('dashboard.head');
        });

    Route::middleware(['password.changed', 'role:user'])
        ->prefix('pegawai')
        ->group(function (): void {

            Route::get(
                '/',
                [DashboardController::class, 'user']
            )->name('dashboard.user');

            Route::get(
                '/realisasi',
                [RealizationController::class, 'show']
            )->name('realizations.show');

            Route::post(
                '/realisasi',
                [RealizationController::class, 'store']
            )->name('realizations.store');
            Route::get('/documents/laporan-perjadin', [DocumentController::class, 'laporanPerjadin'])->name(
                'documents.laporan-perjadin'
            );
            Route::get(
                '/perjalanan/{travel}/laporan',
                [TravelReportController::class, 'edit']
            )->name('travel-reports.edit');

            Route::put(
                '/perjalanan/{travel}/laporan',
                [TravelReportController::class, 'update']
            )->name('travel-reports.update');
        });
    Route::middleware(['password.changed', 'role:verifikator'])
        ->prefix('verifikator')
        ->group(function (): void {

            Route::get(
                '/',
                [DashboardController::class, 'verifier']
            )->name('dashboard.verifier');


            Route::get(
                '/verifikasi',
                [VerificationController::class, 'show']
            )->name('verifications.show');

            Route::post(
                '/verifikasi',
                [VerificationController::class, 'store']
            )->name('verifications.store');
        });

    /*
     * Alias sementara untuk URL aplikasi PHP native.
     * Semua halaman utama tetap menggunakan URL Laravel modern.
     */
    Route::middleware(['password.changed', 'role:admin'])->group(function (): void {
        Route::get('/dashboard_admin.php', fn() => redirect()->route('dashboard.admin'))
            ->name('legacy.dashboard.admin');
        Route::get('/manajemen_pegawai.php', fn() => redirect()->route('employees.index'))
            ->name('legacy.employees.index');
        Route::post('/proses_tambah_pegawai.php', [EmployeeController::class, 'store']);
        Route::post('/proses_edit_pegawai.php', [EmployeeController::class, 'update']);
        Route::post('/hapus_pegawai.php', [EmployeeController::class, 'destroy']);
    });

    Route::middleware(['password.changed', 'role:officer'])->group(function (): void {
        Route::get('/dashboard_officer.php', fn() => redirect()->route('dashboard.officer'))
            ->name('legacy.dashboard.officer');
        Route::post('/proses_tambah_spt.php', [TravelOrderController::class, 'store']);
    });

    Route::middleware(['password.changed', 'role:program'])->get(
        '/dashboard_program.php',
        fn() => redirect()->route('dashboard.program')
    )->name('legacy.dashboard.program');

    Route::middleware(['password.changed', 'role:head'])->get(
        '/dashboard_head.php',
        fn() => redirect()->route('dashboard.head')
    )->name('legacy.dashboard.head');

    Route::middleware(['password.changed', 'role:user'])->group(function (): void {
        Route::get('/dashboard_user.php', fn() => redirect()->route('dashboard.user'))
            ->name('legacy.dashboard.user');
        Route::get('/form_realisasi.php', [RealizationController::class, 'show']);
        Route::post('/proses_lapor.php', [RealizationController::class, 'store']);
        Route::get('/pegawai/profil', fn() => redirect()->route('profile.edit'));
    });

    Route::middleware(['password.changed', 'role:verifikator'])->group(function (): void {
        Route::get('/dashboard_verifikator.php', fn() => redirect()->route('dashboard.verifier'))
            ->name('legacy.dashboard.verifier');
        Route::post('/proses_approve.php', [VerificationController::class, 'store']);
    });

    Route::middleware('password.changed')->get(
        '/generate_spt.php',
        [DocumentController::class, 'suratTugas']
    )->name('legacy.documents.surat-tugas');
});
