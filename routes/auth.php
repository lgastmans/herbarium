<?php

use App\Http\Controllers\Admin\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    // Volt::route('register', 'admin.livewire.pages.auth.register')
    //     ->name('register');

    Volt::route('login', 'admin.livewire.pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'admin.livewire.pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'admin.livewire.pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'admin.livewire.pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'admin.livewire.pages.auth.confirm-password')
        ->name('password.confirm');
});
