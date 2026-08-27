<?php

namespace App\Jobs;

use App\Models\CatalogSnapshot;
use App\Services\Catalog\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCatalogPageJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public readonly int $snapshotId,
        public readonly string $type,
        public readonly int $page,
    ) {}

    public function handle(CatalogSyncService $sync): array
    {
        return $sync->syncPage(CatalogSnapshot::query()->findOrFail($this->snapshotId), $this->type, $this->page);
    }
}
