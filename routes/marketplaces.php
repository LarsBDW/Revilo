<?php

use App\Http\Controllers\MarketplaceController;
use Illuminate\Support\Facades\Route;

// Random public photo URLs are required for the marketplaces to fetch product photos.
Route::get('/marketplace-photos/{file}', [MarketplaceController::class, 'media'])->where('file', '[a-f0-9]{48}\.(jpg|jpeg|png|webp)')->name('marketplaces.photo');
Route::prefix('admin/marketplaces')->middleware(['locale', 'auth', 'admin'])->name('admin.marketplaces.')->group(function () {
    Route::get('/', [MarketplaceController::class, 'index'])->name('index');
    Route::prefix('api')->group(function () {
        Route::get('/session', [MarketplaceController::class, 'session'])->name('api.session');
        Route::get('/products', [MarketplaceController::class, 'products'])->name('api.products');
        Route::post('/products', [MarketplaceController::class, 'save'])->name('api.save');
        Route::get('/inventory', [MarketplaceController::class, 'inventory'])->name('api.inventory');
        Route::post('/import-unit', [MarketplaceController::class, 'attach'])->name('api.attach');
        Route::post('/photos', [MarketplaceController::class, 'upload'])->middleware('throttle:60,1')->name('api.photos');
        Route::get('/channels', [MarketplaceController::class, 'channels'])->name('api.channels');
        Route::match(['get', 'post'], '/connections/{provider}', [MarketplaceController::class, 'connection'])->where('provider', 'ebay|marktplaats|belgium')->name('api.connection');
        Route::get('/lookup', [MarketplaceController::class, 'lookup'])->middleware('throttle:20,1')->name('api.lookup');
        Route::get('/jobs', [MarketplaceController::class, 'jobs'])->name('api.jobs');
        Route::post('/products/{draft}/operation', [MarketplaceController::class, 'operation'])->whereNumber('draft')->middleware('throttle:20,1')->name('api.operation');
        Route::post('/products/{draft}/sold', [MarketplaceController::class, 'sold'])->whereNumber('draft')->name('api.sold');
        Route::post('/products/{draft}/link', [MarketplaceController::class, 'link'])->whereNumber('draft')->name('api.link');
        Route::get('/products/{draft}/pack', [MarketplaceController::class, 'pack'])->whereNumber('draft')->name('api.pack');
        Route::get('/export', [MarketplaceController::class, 'pack'])->name('api.export');
    });
});
