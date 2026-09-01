<?php

use App\Http\Controllers\AdminWebAuthController;
use App\Http\Controllers\AdminWebController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return Auth::guard('web')->user()?->role === 'admin'
        ? redirect()->route('admin.dashboard')
        : redirect()->route('admin.login');
});

Route::get('/admin/login', [AdminWebAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminWebAuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('admin.login.store');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin.web'])->group(function () {
    Route::get('/', [AdminWebController::class, 'dashboard'])->name('dashboard');
    Route::get('/sync-status/{id}', [AdminWebController::class, 'syncStatus'])->name('sync-status');
    Route::post('/logout', [AdminWebAuthController::class, 'logout'])->name('logout');

    Route::post('/users', [AdminWebController::class, 'storeUser'])->name('users.store');
    Route::put('/users/{id}', [AdminWebController::class, 'updateUser'])->name('users.update');
    Route::put('/subscriptions/{id}', [AdminWebController::class, 'updateSubscription'])->name('subscriptions.update');
    Route::post('/plans', [AdminWebController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{id}', [AdminWebController::class, 'updatePlan'])->name('plans.update');
    Route::put('/channels/{id}', [AdminWebController::class, 'updateChannel'])->name('channels.update');

    Route::put('/iptv-playlists', [AdminWebController::class, 'updateIptvPlaylists'])->name('iptv-playlists.update');
    Route::post('/iptv-playlists/add', [AdminWebController::class, 'addIptvPlaylist'])->name('iptv-playlists.add');
    Route::delete('/iptv-playlists/{id}', [AdminWebController::class, 'removeIptvPlaylist'])->name('iptv-playlists.remove');
    Route::post('/iptv-playlists/sync', [AdminWebController::class, 'syncIptvPlaylists'])->name('iptv-playlists.sync');
    Route::post('/iptv-resources/refresh', [AdminWebController::class, 'refreshIptvResources'])->name('iptv-resources.refresh');

    Route::put('/iptv-vod-playlists', [AdminWebController::class, 'updateIptvVodPlaylists'])->name('iptv-vod-playlists.update');
    Route::post('/iptv-vod-playlists/add', [AdminWebController::class, 'addIptvVodPlaylist'])->name('iptv-vod-playlists.add');
    Route::delete('/iptv-vod-playlists/{id}', [AdminWebController::class, 'removeIptvVodPlaylist'])->name('iptv-vod-playlists.remove');
    Route::post('/iptv-vod-playlists/sync', [AdminWebController::class, 'syncIptvVodPlaylists'])->name('iptv-vod-playlists.sync');

    Route::put('/iptv-proxies', [AdminWebController::class, 'updateIptvProxies'])->name('iptv-proxies.update');
    Route::post('/iptv-proxies/add', [AdminWebController::class, 'addIptvProxy'])->name('iptv-proxies.add');
    Route::delete('/iptv-proxies/{id}', [AdminWebController::class, 'removeIptvProxy'])->name('iptv-proxies.remove');

    Route::put('/stream-fallback', [AdminWebController::class, 'updateStreamFallback'])->name('stream-fallback.update');
    Route::post('/stream-fallback/addon', [AdminWebController::class, 'addStremioAddon'])->name('stream-fallback.addon');
    Route::delete('/stream-fallback/addon/{id}', [AdminWebController::class, 'removeStremioAddon'])->name('stream-fallback.addon.remove');
    Route::post('/stream-fallback/verify-content', [AdminWebController::class, 'verifyStremioContent'])->name('stream-fallback.verify-content');
    Route::post('/stream-fallback/sync-catalog', [AdminWebController::class, 'syncStreamFallbackCatalog'])->name('stream-fallback.sync-catalog');

    Route::put('/stremio/catalog', [AdminWebController::class, 'updateStremioCatalog'])->name('stremio-catalog.update');
    Route::post('/stremio/catalog/addon', [AdminWebController::class, 'addStremioCatalogAddon'])->name('stremio-catalog.addon');
    Route::delete('/stremio/catalog/addon/{id}', [AdminWebController::class, 'removeStremioCatalogAddon'])->name('stremio-catalog.addon.remove');
    Route::post('/stremio/catalog/sync', [AdminWebController::class, 'syncStremioCatalog'])->name('stremio-catalog.sync');
    Route::put('/stremio/streams', [AdminWebController::class, 'updateStremioStreams'])->name('stremio-streams.update');
    Route::post('/stremio/streams/addon', [AdminWebController::class, 'addStremioStreamAddon'])->name('stremio-streams.addon');
    Route::delete('/stremio/streams/addon/{id}', [AdminWebController::class, 'removeStremioStreamAddon'])->name('stremio-streams.addon.remove');

    Route::post('/trials', [AdminWebController::class, 'createTrial'])->name('trials.store');
});
