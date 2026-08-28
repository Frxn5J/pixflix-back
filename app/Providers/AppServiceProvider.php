<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        $this->tuneSqlite();
    }

    /**
     * WAL lets readers and one writer work concurrently, and the busy timeout
     * turns "database is locked" errors into short waits. Both are no-ops on
     * in-memory databases, so the test-suite is unaffected.
     */
    private function tuneSqlite(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        try {
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA synchronous=NORMAL');
            DB::statement('PRAGMA busy_timeout=5000');
        } catch (Throwable) {
            // Best effort: a read-only or exotic database file must not boot-fail the app.
        }
    }
}
