<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdministrator;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            EnsureUserIsAdministrator::class,
        ]);
    }
}
