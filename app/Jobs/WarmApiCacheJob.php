<?php

namespace App\Jobs;

use App\Services\ApiCacheWarmupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WarmApiCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly bool $force = false)
    {
        $this->onQueue('low');
    }

    public function handle(ApiCacheWarmupService $warmup): void
    {
        $lock = Cache::lock('pixflix:cache:warmup:lock', 600);

        if (! $lock->get()) {
            return;
        }

        try {
            if (! $this->force && Cache::has('pixflix:cache:warmup:completed')) {
                return;
            }

            $result = $warmup->warm();
            Cache::forever('pixflix:cache:warmup:completed', [
                'warmed_at' => now()->toIso8601String(),
                'result' => $result,
            ]);
            Log::info('API cache warmup completed', ['result' => $result]);
        } finally {
            $lock->release();
        }
    }
}
