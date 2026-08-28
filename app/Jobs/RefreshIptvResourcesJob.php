<?php

namespace App\Jobs;

use App\Services\Iptv\IptvResourceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Refreshes external IPTV resources (logos, stream headers). Queued
 * (opt-in) so the admin request returns immediately.
 */
class RefreshIptvResourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(IptvResourceSyncService $sync): void
    {
        $sync->run();
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
