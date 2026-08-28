<?php

namespace App\Providers;

use App\Services\Documents\PdfConverter;
use App\Services\NavigationWorkCount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */

    public function register(): void
    {
        $this->app->bind(PdfConverter::class, fn () => new PdfConverter(
            (string) config('sim_pd.documents.libreoffice.binary'),
            (int) config('sim_pd.documents.libreoffice.timeout', 60),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        View::composer('layouts.app', function ($view): void {
            $user = Auth::user();
            $view->with(
                'navigationWorkCount',
                $user ? app(NavigationWorkCount::class)->for($user) : 0
            );
        });
    }
}
