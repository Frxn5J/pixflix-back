<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PlaybackController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrialController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('api.v1.auth.logout');
    });

    Route::post('/trials', [TrialController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:admin,agent'])
        ->name('api.v1.trials.store');

    Route::get('/channels/{id}/stream', [ChannelController::class, 'stream'])
        ->name('api.v1.channels.stream');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.me');
        Route::put('/me/password', [AuthController::class, 'updatePassword'])
            ->name('api.v1.me.password');

        Route::prefix('admin')->middleware('role:admin,agent')->group(function () {
            Route::get('/overview', [AdminController::class, 'overview'])->name('api.v1.admin.overview');
            Route::get('/users', [AdminController::class, 'users'])->name('api.v1.admin.users');
            Route::put('/users/{id}', [AdminController::class, 'updateUser'])->name('api.v1.admin.users.update');
            Route::get('/subscriptions', [AdminController::class, 'subscriptions'])->name('api.v1.admin.subscriptions');
            Route::put('/subscriptions/{id}', [AdminController::class, 'updateSubscription'])->name('api.v1.admin.subscriptions.update');
            Route::get('/plans', [AdminController::class, 'plans'])->name('api.v1.admin.plans');
            Route::post('/plans', [AdminController::class, 'storePlan'])->name('api.v1.admin.plans.store');
            Route::put('/plans/{id}', [AdminController::class, 'updatePlan'])->name('api.v1.admin.plans.update');
            Route::get('/channels', [AdminController::class, 'channels'])->name('api.v1.admin.channels');
            Route::put('/channels/{id}', [AdminController::class, 'updateChannel'])->name('api.v1.admin.channels.update');
            Route::get('/iptv-proxies', [AdminController::class, 'iptvProxies'])->name('api.v1.admin.iptv-proxies');
            Route::put('/iptv-proxies', [AdminController::class, 'updateIptvProxies'])->name('api.v1.admin.iptv-proxies.update');
            Route::get('/stream-fallback', [AdminController::class, 'streamFallback'])
                ->middleware('role:admin')
                ->name('api.v1.admin.stream-fallback');
            Route::put('/stream-fallback', [AdminController::class, 'updateStreamFallback'])
                ->middleware('role:admin')
                ->name('api.v1.admin.stream-fallback.update');
            Route::post('/stream-fallback/verify', [AdminController::class, 'verifyStreamFallbackAddon'])
                ->middleware('role:admin')
                ->name('api.v1.admin.stream-fallback.verify');
            Route::post('/stream-fallback/verify-content', [AdminController::class, 'verifyStreamFallbackContent'])
                ->middleware('role:admin')
                ->name('api.v1.admin.stream-fallback.verify-content');
        });

        Route::middleware('subscription.active')->group(function () {
            Route::get('/catalog', [CatalogController::class, 'index'])->name('api.v1.catalog.index');
            Route::get('/catalog/featured', [CatalogController::class, 'featured'])->name('api.v1.catalog.featured');
            Route::get('/catalog/genres', [CatalogController::class, 'genres'])->name('api.v1.catalog.genres');
            Route::get('/titles/{slug}', [CatalogController::class, 'show'])->name('api.v1.titles.show');
            Route::get('/titles/{slug}/streams', [PlaybackController::class, 'titleStreams'])->name('api.v1.titles.streams');
            Route::get('/episodes/{id}/streams', [PlaybackController::class, 'episodeStreams'])->name('api.v1.episodes.streams');
            Route::post('/catalog/resolve', [PlaybackController::class, 'resolve'])->name('api.v1.catalog.resolve');
            Route::get('/progress/continue-watching', [PlaybackController::class, 'continueWatching'])->name('api.v1.progress.continue');
            Route::put('/progress', [PlaybackController::class, 'updateProgress'])->name('api.v1.progress.update');
            Route::get('/profiles', [ProfileController::class, 'index'])->name('api.v1.profiles.index');
            Route::post('/profiles', [ProfileController::class, 'store'])->name('api.v1.profiles.store');
            Route::put('/profiles/{id}', [ProfileController::class, 'update'])->name('api.v1.profiles.update');
            Route::delete('/profiles/{id}', [ProfileController::class, 'destroy'])->name('api.v1.profiles.destroy');
            Route::get('/profiles/{profileId}/favorites', [FavoriteController::class, 'index'])->name('api.v1.profiles.favorites.index');
            Route::post('/profiles/{profileId}/favorites', [FavoriteController::class, 'store'])->name('api.v1.profiles.favorites.store');
            Route::delete('/profiles/{profileId}/favorites/{titleId}', [FavoriteController::class, 'destroy'])->name('api.v1.profiles.favorites.destroy');
            Route::get('/channels', [ChannelController::class, 'index'])->name('api.v1.channels.index');
            Route::get('/channels/{id}', [ChannelController::class, 'show'])->name('api.v1.channels.show');
            Route::get('/channels/{id}/epg', [ChannelController::class, 'epg'])->name('api.v1.channels.epg');
            Route::get('/epg/now', [ChannelController::class, 'now'])->name('api.v1.epg.now');
        });
    });
});
