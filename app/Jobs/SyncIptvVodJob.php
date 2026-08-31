<?php

namespace App\Jobs;

use App\Services\IptvVod\IptvVodSyncService;
use App\Services\SyncProgressService;
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

    public function __construct(public readonly string $progressId) {}

    public function handle(IptvVodSyncService $sync, SyncProgressService $progress): void
    {
        try {
            $result = $sync->run($this->progressId);
            $progress->complete($this->progressId, $result);
        } catch (Throwable $exception) {
            $progress->fail($this->progressId, $exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(SyncProgressService::class)->fail($this->progressId, $exception);
        report($exception);
    }
}
