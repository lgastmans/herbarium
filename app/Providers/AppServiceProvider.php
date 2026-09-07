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
        // WireUI's deferred asset is encountered before Livewire in the app
        // layout and must register its Alpine components before alpine:init.
        Livewire::useScriptTagAttributes([
            'defer' => true,
        ]);

        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            EnsureUserIsAdministrator::class,
        ]);
    }
}
