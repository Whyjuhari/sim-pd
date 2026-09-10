<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecapExportController;
use App\Http\Controllers\RealizationController;
use App\Http\Controllers\RealizationEvidenceController;
use App\Http\Controllers\TravelOrderController;
use App\Http\Controllers\TravelReportController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\VerificationHistoryController;
use App\Http\Controllers\BudgetSettingController;
use App\Http\Controllers\DailyAllowanceImportController;
use App\Http\Controllers\HotelRateImportController;
use App\Http\Controllers\GroundTransportImportController;
use App\Http\Controllers\AirTransportImportController;
use App\Http\Controllers\SptTemplateController;
use App\Http\Controllers\SptSrikandiController;
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

    Route::get(
        '/documents/bukti-realisasi/{evidence}',
        [RealizationEvidenceController::class, 'show']
    )
        ->middleware('password.changed')
        ->whereNumber('evidence')
        ->name('realization-evidence.show');

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

            Route::get('/spt-srikandi', [SptSrikandiController::class, 'index'])
                ->name('spt-srikandi.index');
            Route::get('/spt-srikandi/{sptGroupId}', [SptSrikandiController::class, 'show'])
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.show');
            Route::post('/spt/{sptGroupId}/mark-sent', [SptSrikandiController::class, 'markSent'])
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.mark-sent');
            Route::post('/spt-srikandi/{sptGroupId}/upload', [SptSrikandiController::class, 'upload'])
                ->middleware('throttle:10,1')
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.upload');
            Route::get('/spt-srikandi/{sptGroupId}/document', [SptSrikandiController::class, 'document'])
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.document');
            Route::get('/spt-srikandi/{sptGroupId}/draft-document', [SptSrikandiController::class, 'draftDocument'])
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.draft-document');
            Route::post('/spt-srikandi/{sptGroupId}/publish', [SptSrikandiController::class, 'publish'])
                ->whereUuid('sptGroupId')
                ->name('spt-srikandi.publish');

            Route::get(
                '/spt/create',
                [TravelOrderController::class, 'create']
            )->name('travel-orders.create');

            Route::post(
                '/spt',
                [TravelOrderController::class, 'store']
            )->name('travel-orders.store');

            Route::post(
                '/spt/preview-biaya',
                [TravelOrderController::class, 'previewCost']
            )->middleware('throttle:30,1')->name('travel-orders.cost-preview');

            Route::get(
                '/spt/{sptGroupId}',
                [TravelOrderController::class, 'show']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.show');

            Route::post(
                '/spt/{sptGroupId}/record-srikandi-number',
                [TravelOrderController::class, 'recordSrikandiNumber']
            )
                ->whereUuid('sptGroupId')
                ->name('travel-orders.record-srikandi-number');

            Route::get(
                '/template-spt',
                [SptTemplateController::class, 'index']
            )->name('spt-templates.index');
            Route::post(
                '/template-spt',
                [SptTemplateController::class, 'store']
            )->middleware('throttle:10,1')->name('spt-templates.store');
            Route::put(
                '/template-spt/{template}/default',
                [SptTemplateController::class, 'setDefault']
            )->whereNumber('template')->name('spt-templates.default');
            Route::post(
                '/template-spt/{template}/toggle',
                [SptTemplateController::class, 'toggleActive']
            )->whereNumber('template')->name('spt-templates.toggle');
            Route::get(
                '/template-spt/{template}/unduh',
                [SptTemplateController::class, 'download']
            )->whereNumber('template')->name('spt-templates.download');
            Route::get(
                '/template-spt/{template}/thumbnail',
                [SptTemplateController::class, 'thumbnail']
            )->whereNumber('template')->name('spt-templates.thumbnail');
            Route::get(
                '/template-spt/bawaan/{variant}/thumbnail',
                [SptTemplateController::class, 'builtInThumbnail']
            )->where('variant', '[a-z_]+')->name('spt-templates.built-in-thumbnail');
            Route::delete(
                '/template-spt/{template}',
                [SptTemplateController::class, 'destroy']
            )->whereNumber('template')->name('spt-templates.destroy');

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

            Route::get('/rekap/export/{format}', [RecapExportController::class, 'program'])
                ->whereIn('format', ['xlsx', 'pdf'])
                ->name('program.recap.export');

            Route::get('/anggaran', [BudgetSettingController::class, 'edit'])
                ->name('program.budget.edit');
            Route::put('/anggaran/dipa/{fiscalYear}', [BudgetSettingController::class, 'upsertDipa'])
                ->whereNumber('fiscalYear')
                ->name('program.dipa.update');
            Route::get('/anggaran/uang-harian/template', [DailyAllowanceImportController::class, 'template'])
                ->name('program.daily-allowances.template');
            Route::post('/anggaran/uang-harian/impor', [DailyAllowanceImportController::class, 'upload'])
                ->middleware('throttle:10,1')
                ->name('program.daily-allowances.imports.upload');
            Route::get('/anggaran/uang-harian/impor/{import}', [DailyAllowanceImportController::class, 'preview'])
                ->name('program.daily-allowances.imports.preview');
            Route::post('/anggaran/uang-harian/impor/{import}/simpan', [DailyAllowanceImportController::class, 'commit'])
                ->name('program.daily-allowances.imports.commit');
            Route::delete('/anggaran/uang-harian/impor/{import}', [DailyAllowanceImportController::class, 'discard'])
                ->name('program.daily-allowances.imports.discard');
            Route::post('/anggaran/uang-harian/{regulation}/aktifkan', [DailyAllowanceImportController::class, 'activate'])
                ->whereNumber('regulation')
                ->name('program.daily-allowances.activate');
            Route::get('/anggaran/uang-harian/{regulation}/csv', [DailyAllowanceImportController::class, 'download'])
                ->whereNumber('regulation')
                ->name('program.daily-allowances.download');
            Route::get('/anggaran/penginapan/template', [HotelRateImportController::class, 'template'])
                ->name('program.hotel-rates.template');
            Route::post('/anggaran/penginapan/impor', [HotelRateImportController::class, 'upload'])
                ->middleware('throttle:10,1')
                ->name('program.hotel-rates.imports.upload');
            Route::get('/anggaran/penginapan/impor/{import}', [HotelRateImportController::class, 'preview'])
                ->name('program.hotel-rates.imports.preview');
            Route::post('/anggaran/penginapan/impor/{import}/simpan', [HotelRateImportController::class, 'commit'])
                ->name('program.hotel-rates.imports.commit');
            Route::delete('/anggaran/penginapan/impor/{import}', [HotelRateImportController::class, 'discard'])
                ->name('program.hotel-rates.imports.discard');
            Route::post('/anggaran/penginapan/{regulation}/aktifkan', [HotelRateImportController::class, 'activate'])
                ->whereNumber('regulation')
                ->name('program.hotel-rates.activate');
            Route::get('/anggaran/penginapan/{regulation}/csv', [HotelRateImportController::class, 'download'])
                ->whereNumber('regulation')
                ->name('program.hotel-rates.download');
            Route::get('/anggaran/transportasi-darat/template', [GroundTransportImportController::class, 'template'])
                ->name('program.ground-transport.template');
            Route::post('/anggaran/transportasi-darat/impor', [GroundTransportImportController::class, 'upload'])
                ->middleware('throttle:10,1')
                ->name('program.ground-transport.imports.upload');
            Route::get('/anggaran/transportasi-darat/impor/{import}', [GroundTransportImportController::class, 'preview'])
                ->name('program.ground-transport.imports.preview');
            Route::post('/anggaran/transportasi-darat/impor/{import}/simpan', [GroundTransportImportController::class, 'commit'])
                ->name('program.ground-transport.imports.commit');
            Route::delete('/anggaran/transportasi-darat/impor/{import}', [GroundTransportImportController::class, 'discard'])
                ->name('program.ground-transport.imports.discard');
            Route::post('/anggaran/transportasi-darat/{regulation}/aktifkan', [GroundTransportImportController::class, 'activate'])
                ->whereNumber('regulation')
                ->name('program.ground-transport.activate');
            Route::get('/anggaran/transportasi-darat/{regulation}/csv', [GroundTransportImportController::class, 'download'])
                ->whereNumber('regulation')
                ->name('program.ground-transport.download');
            Route::get('/anggaran/transportasi-udara/template-terminal', [AirTransportImportController::class, 'terminalTemplate'])
                ->name('program.air-transport.terminal-template');
            Route::get('/anggaran/transportasi-udara/template-tiket', [AirTransportImportController::class, 'airfareTemplate'])
                ->name('program.air-transport.airfare-template');
            Route::post('/anggaran/transportasi-udara/impor', [AirTransportImportController::class, 'upload'])
                ->middleware('throttle:10,1')
                ->name('program.air-transport.imports.upload');
            Route::get('/anggaran/transportasi-udara/impor/{import}', [AirTransportImportController::class, 'preview'])
                ->name('program.air-transport.imports.preview');
            Route::post('/anggaran/transportasi-udara/impor/{import}/simpan', [AirTransportImportController::class, 'commit'])
                ->name('program.air-transport.imports.commit');
            Route::delete('/anggaran/transportasi-udara/impor/{import}', [AirTransportImportController::class, 'discard'])
                ->name('program.air-transport.imports.discard');
            Route::post('/anggaran/transportasi-udara/{regulation}/aktifkan', [AirTransportImportController::class, 'activate'])
                ->whereNumber('regulation')
                ->name('program.air-transport.activate');
            Route::get('/anggaran/transportasi-udara/{regulation}/csv-terminal', [AirTransportImportController::class, 'downloadTerminal'])
                ->whereNumber('regulation')
                ->name('program.air-transport.download-terminal');
            Route::get('/anggaran/transportasi-udara/{regulation}/csv-tiket', [AirTransportImportController::class, 'downloadAirfare'])
                ->whereNumber('regulation')
                ->name('program.air-transport.download-airfare');
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

            Route::get('/rekap/export/{format}', [RecapExportController::class, 'head'])
                ->whereIn('format', ['xlsx', 'pdf'])
                ->name('head.recap.export');
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

            Route::post(
                '/perjalanan/{travel}/laporan/preview',
                [TravelReportController::class, 'preview']
            )->name('travel-reports.preview');

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

            Route::get('/riwayat', VerificationHistoryController::class)
                ->name('verifications.history');
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
