<?php

namespace App\Jobs;

use App\Services\IptvVod\IptvVodSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * VOD playlist sync parses large M3U lists and hits TMDB per movie, so it
 * can run for a long time. Queued (opt-in) to keep PHP-FPM workers free.
 */
class SyncIptvVodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function handle(IptvVodSyncService $sync): void
    {
        $sync->run();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
