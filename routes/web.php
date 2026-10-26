<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/public.php';

Route::prefix('admin')->group(function () {
    require __DIR__.'/auth.php';

    // Profile is available before verification so users can correct their email.
    Route::view('profile', 'admin.profile')->middleware('auth')->name('admin.profile');

    Route::middleware(['auth', 'verified'])->name('admin.')->group(__DIR__.'/admin.php');
});
