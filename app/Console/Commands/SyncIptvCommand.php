<?php

namespace App\Console\Commands;

use App\Services\IptvOrg\IptvOrgSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncIptvCommand extends Command
{
    protected $signature = 'pixflix:sync-iptv
                            {--country= : Filtrar por codigo de pais ISO alpha-2, por ejemplo MX}
                            {--language= : Filtrar por codigo de idioma, por ejemplo spa}
                            {--limit= : Limitar el numero de canales sincronizados}';

    protected $description = 'Sincroniza canales y streams publicos desde una playlist M3U de iptv-org';

    public function handle(IptvOrgSyncService $sync): int
    {
        try {
            $result = $sync->run(
                $this->option('country') !== null ? (string) $this->option('country') : config('pixflix.iptv.country'),
                $this->option('language') !== null ? (string) $this->option('language') : null,
                $this->option('limit') !== null ? (int) $this->option('limit') : config('pixflix.iptv.max_channels'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'IPTV sincronizado: %d canales, %d streams disponibles, %d canales desactivados.',
            $result['channels'],
            $result['streams'],
            $result['deactivated'],
        ));

        return self::SUCCESS;
    }
}
