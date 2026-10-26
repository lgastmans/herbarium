<?php

use App\Http\Controllers\PublicSite\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('public.home');
