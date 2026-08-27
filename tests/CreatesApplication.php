<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Tests may prepare an empty testing database, but they must never
        // destroy or recreate an existing database. DatabaseTransactions in
        // the feature suites rolls each test back after it finishes.
        if ($app->environment('testing')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        return $app;
    }
}
