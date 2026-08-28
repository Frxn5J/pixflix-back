<?php

namespace App\Console\Commands;

use App\Services\Iptv\IptvResourceSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncIptvResourcesCommand extends Command
{
    protected $signature = 'pixflix:sync-iptv-resources';

    protected $description = 'Sincroniza cada recurso IPTV configurado, incluidos canales y VOD';

    public function handle(IptvResourceSyncService $sync): int
    {
        try {
            $result = $sync->run();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['live'] !== null) {
            $this->info(sprintf(
                'Canales IPTV: %d canales, %d streams.',
                $result['live']['channels'],
                $result['live']['streams'],
            ));
        }
        if (($result['vod']['status'] ?? null) === 'skipped') {
            $this->line('VOD IPTV: omitido, no hay listas activas.');
        } elseif ($result['vod'] !== null) {
            $this->info(sprintf(
                'VOD IPTV: %d películas, %d series, %d episodios.',
                $result['vod']['movies'],
                $result['vod']['series'],
                $result['vod']['episodes'],
            ));
        }

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
