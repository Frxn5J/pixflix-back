<?php

namespace App\Services\Iptv;

use App\Services\IptvOrg\IptvOrgSyncService;
use App\Services\IptvVod\IptvVodSyncService;
use App\Services\SyncSettings;
use RuntimeException;
use Throwable;

class IptvResourceSyncService
{
    public function __construct(
        private readonly IptvOrgSyncService $live,
        private readonly IptvVodSyncService $vod,
        private readonly SyncSettings $settings,
    ) {}

    /** @return array{live: array<string, mixed>|null, vod: array<string, mixed>|null, errors: array<int, string>} */
    public function run(): array
    {
        $result = ['live' => null, 'vod' => null, 'errors' => []];

        try {
            $result['live'] = $this->live->run(
                config('pixflix.iptv.country'),
                null,
                config('pixflix.iptv.max_channels'),
            );
        } catch (Throwable $exception) {
            $result['errors'][] = 'Canales: '.$exception->getMessage();
        }

        if ($this->hasEnabledVodPlaylist()) {
            try {
                $result['vod'] = $this->vod->run();
            } catch (Throwable $exception) {
                $result['errors'][] = 'VOD: '.$exception->getMessage();
            }
        } else {
            $result['vod'] = ['status' => 'skipped', 'message' => 'No hay listas VOD activas.'];
        }

        if ($result['live'] === null && ($result['vod']['status'] ?? null) === 'skipped' && $result['errors'] !== []) {
            throw new RuntimeException(implode(' ', $result['errors']));
        }

        return $result;
    }

    private function hasEnabledVodPlaylist(): bool
    {
        $playlists = $this->settings->get('iptv.vod_playlists', []);

        if (! is_array($playlists)) {
            return false;
        }

        foreach ($playlists as $playlist) {
            if (is_array($playlist) && ! empty($playlist['url']) && ($playlist['enabled'] ?? true)) {
                return true;
            }
        }

        return false;
    }
}
