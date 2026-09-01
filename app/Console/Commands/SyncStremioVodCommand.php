<?php

namespace App\Console\Commands;

use App\Services\Catalog\StremioCatalogSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncStremioVodCommand extends Command
{
    protected $signature = 'pixflix:sync-stremio-vod {--force : Ignora la marca temporal de la última sincronización}';

    protected $description = 'Sincroniza el catálogo VOD desde el único addon Stremio configurado';

    public function handle(StremioCatalogSyncService $sync): int
    {
        try {
            $result = $sync->sync((bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

        return in_array($result['status'], ['success', 'disabled'], true) ? self::SUCCESS : self::FAILURE;
    }
}
