<?php

namespace App\Jobs;

use App\Models\CatalogSnapshot;
use App\Services\Catalog\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCatalogTitleJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public readonly int $snapshotId,
        public readonly array $item,
    ) {}

    public function handle(CatalogSyncService $sync): void
    {
        $sync->syncTitle(CatalogSnapshot::query()->findOrFail($this->snapshotId), $this->item);
    }
}
