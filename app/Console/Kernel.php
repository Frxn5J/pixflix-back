<?php

namespace App\Console;

use App\Services\SyncSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $settings = app(SyncSettings::class);
        $schedule->command('pixflix:sync-catalog')
            ->dailyAt((string) $settings->get('sync.cron_hour', config('pixflix.sync.cron_hour', '04:00')))
            ->timezone((string) $settings->get('sync.timezone', config('pixflix.sync.timezone', 'UTC')))
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('pixflix:expire-trials')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('pixflix:sync-iptv')
            ->dailyAt((string) config('pixflix.iptv.sync_cron', '03:30'))
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
