<?php

use App\Http\Controllers\Admin\CollectorController;
use App\Http\Controllers\Admin\DistrictController;
use App\Http\Controllers\Admin\FamilyController;
use App\Http\Controllers\Admin\GenusController;
use App\Http\Controllers\Admin\GenusImageController;
use App\Http\Controllers\Admin\HerbariumCollectionSearchController;
use App\Http\Controllers\Admin\HerbariumImageController;
use App\Http\Controllers\Admin\HerbariumImportTemporaryPreviewController;
use App\Http\Controllers\Admin\PlaceController;
use App\Http\Controllers\Admin\PlantController;
use App\Http\Controllers\Admin\SpecificController;
use App\Http\Controllers\Admin\StateController;
use App\Http\Controllers\Admin\StatusController;
use App\Http\Controllers\Admin\TalukController;
use App\Http\Controllers\Admin\UserController;
use App\Livewire\Admin\CreatePlant;
use App\Livewire\Admin\ImportHerbariumImages;
use App\Livewire\Admin\ReplaceFamily;
use App\Livewire\Admin\ReplaceGenus;
use App\Livewire\Admin\ReplacePlace;
use App\Livewire\Admin\UpdatePlant;
use App\Livewire\Admin\UploadGenusImage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'admin.dashboard')->name('dashboard');

Route::get('plants', [PlantController::class, 'index'])
    ->name('plants');
Route::get('/plants/create', CreatePlant::class)->name('plants.create');
Route::get('/plants/update/{herbarium}', UpdatePlant::class)->name('plants.update');

Route::get('/plants/replace-genus', ReplaceGenus::class)->name('genus.replace');
Route::get('/plants/replace-family', ReplaceFamily::class)->name('family.replace');
Route::get('/plants/replace-place', ReplacePlace::class)->name('place.replace');

Route::get('/herbarium/images/import', ImportHerbariumImages::class)
    ->middleware(['admin'])
    ->name('herbarium.images.import');

Route::get('/herbarium/images/import-preview/{token}', HerbariumImportTemporaryPreviewController::class)
    ->whereUuid('token')
    ->middleware(['admin', 'signed', 'throttle:240,1'])
    ->name('herbarium.images.import.preview');

Route::get('/families', [FamilyController::class, 'index'])->name('families');
Route::get('/genus', [GenusController::class, 'index'])->name('genus');
Route::get('/places', [PlaceController::class, 'index'])->name('places');
Route::get('/taluks', [TalukController::class, 'index'])->name('taluks');
Route::get('/districts', [DistrictController::class, 'index'])->name('districts');
Route::get('/states', [StateController::class, 'index'])->name('states');
Route::get('/specifics', [SpecificController::class, 'index'])->name('specifics');
Route::get('/statuses', [StatusController::class, 'index'])->name('statuses');
Route::get('/collectors', [CollectorController::class, 'index'])->name('collectors');

Route::get('/herbarium-label/{id}', [GenusController::class, 'generateLabel'])->name('herbarium-label');
Route::get('/herbarium-label/{id}/view', [GenusController::class, 'generateLabelView'])->name('herbarium-label-view');

Route::get('/herbarium/image/{image}', HerbariumImageController::class)->name('herbarium.image.view');

Route::get('/genus-images', [GenusImageController::class, 'index'])->name('genus-images.index');
Route::post('/genus-images', [GenusImageController::class, 'store'])->name('genus-images.store');
Route::get('/upload-genus-image', UploadGenusImage::class)->name('genus-images.upload');

Route::get('users', [UserController::class, 'index'])
    ->name('users');

Route::prefix('ajax')->group(function () {
    Route::get('/herbaria', HerbariumCollectionSearchController::class)
        ->middleware(['admin', 'throttle:120,1'])
        ->name('ajax.herbaria');
    Route::get('/genus', [GenusController::class, 'getListForSelect'])->name('ajax.genus');
    Route::get('/families', [FamilyController::class, 'getListForSelect'])->name('ajax.families');
    Route::get('/places', [PlaceController::class, 'getListForSelect'])->name('ajax.places');
    Route::get('/taluks', [TalukController::class, 'getListForSelect'])->name('ajax.taluks');
    Route::get('/districts', [DistrictController::class, 'getListForSelect'])->name('ajax.districts');
    Route::get('/states', [StateController::class, 'getListForSelect'])->name('ajax.states');
    Route::get('/specifics', [SpecificController::class, 'getListForSelect'])->name('ajax.specifics');
    Route::get('/statuses', [StatusController::class, 'getListForSelect'])->name('ajax.statuses');
    Route::get('/collectors', [CollectorController::class, 'getListForSelect'])->name('ajax.collectors');
});
