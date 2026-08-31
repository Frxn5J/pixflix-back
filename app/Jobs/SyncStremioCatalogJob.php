<?php

namespace App\Jobs;

use App\Services\Catalog\StremioCatalogSyncService;
use App\Services\SyncProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncStremioCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $progressId) {}

    public function handle(StremioCatalogSyncService $sync, SyncProgressService $progress): void
    {
        try {
            $result = $sync->sync(true, $this->progressId);
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
