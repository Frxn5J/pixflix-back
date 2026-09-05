<?php

namespace App\Jobs;

use App\Services\Iptv\IptvStreamVerifier;
use App\Services\IptvOrg\IptvOrgSyncService;
use App\Services\SyncProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs the live-channel IPTV sync outside the HTTP worker so an admin
 * request never holds a PHP-FPM process for minutes. Only used when
 * pixflix.sync.async is enabled (PIXFLIX_SYNC_ASYNC=true) and a queue
 * worker is deployed (see deploy/pixflix-queue.service.example).
 */
class SyncIptvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $progressId) {}

    public function handle(IptvOrgSyncService $sync, IptvStreamVerifier $verifier, SyncProgressService $progress): void
    {
        try {
            $result = $sync->run(
                (string) config('pixflix.iptv.country'),
                null,
                config('pixflix.iptv.max_channels'),
                $this->progressId,
            );
            $result['verification'] = $verifier->run(
                config('pixflix.iptv.country'),
                config('pixflix.iptv.max_channels'),
            );
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
