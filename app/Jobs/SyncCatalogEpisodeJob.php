<?php

namespace App\Jobs;

use App\Models\CatalogSnapshot;
use App\Services\Catalog\CatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCatalogEpisodeJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public readonly int $snapshotId,
        public readonly int $seasonId,
        public readonly array $episode,
    ) {}

    public function handle(CatalogSyncService $sync): void
    {
        $sync->syncEpisode(
            CatalogSnapshot::query()->findOrFail($this->snapshotId),
            $this->seasonId,
            $this->episode,
        );
    }
}
