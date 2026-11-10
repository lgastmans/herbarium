<?php

use App\Http\Controllers\PublicSite\HomeController;
use App\Http\Controllers\PublicSite\SpecimenController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('public.home');
Route::view('/explore', 'public.explore')->name('public.explore');
Route::get('/explore/{collection}', SpecimenController::class)->name('public.specimen');
Route::view('/about', 'public.about')->name('public.about');
Route::view('/contact', 'public.contact')->name('public.contact');
