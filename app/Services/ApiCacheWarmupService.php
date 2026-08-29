<?php

namespace App\Services;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChannelController;

class ApiCacheWarmupService
{
    /**
     * Warm the public hot paths without making an HTTP request or requiring a
     * user token. The controllers keep the cache-key and invalidation rules in
     * one place, so this cannot drift from the real API responses.
     *
     * @return array{catalog: array<string, mixed>, channels: array<string, mixed>}
     */
    public function warm(): array
    {
        return [
            'catalog' => app(CatalogController::class)->warmCache(),
            'channels' => app(ChannelController::class)->warmCache(),
        ];
    }
}
