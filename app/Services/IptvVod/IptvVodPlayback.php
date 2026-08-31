<?php

namespace App\Services\IptvVod;

use App\Models\Episode;
use App\Models\Title;
use App\Services\Iptv\IptvProxyPool;
use App\Services\SyncSettings;

class IptvVodPlayback
{
    public function __construct(
        private readonly IptvProxyPool $proxyPool,
        private readonly SyncSettings $settings,
    ) {}

    public function titleStreams(Title $title): ?array
    {
        if ($title->source !== 'iptv_vod') {
            return null;
        }

        if (! $title->is_active || $title->stream_url === null) {
            return [];
        }

        if ($this->usesLocalFixtures()) {
            return $this->fixtureStreams($title->source_playlist_id);
        }

        return [$this->streamData(
            $title->stream_url,
            $title->quality,
            $title->languages[0] ?? 'Original',
            $title->source_playlist_id,
        )];
    }

    public function episodeStreams(Episode $episode): ?array
    {
        // Season has a `title` column too, so resolve the relationship explicitly.
        $title = $episode->season?->title()->first();
        if ($episode->source !== 'iptv_vod' && $title?->source !== 'iptv_vod') {
            return null;
        }

        if (! $episode->is_active || ! $title?->is_active || $episode->stream_url === null) {
            return [];
        }

        if ($this->usesLocalFixtures()) {
            return $this->fixtureStreams($episode->source_playlist_id);
        }

        return [$this->streamData(
            $episode->stream_url,
            $title->quality,
            $title->languages[0] ?? 'Original',
            $episode->source_playlist_id,
        )];
    }

    private function streamData(
        string $target,
        ?string $quality,
        string $language,
        ?string $playlistId,
    ): array {
        $path = strtolower((string) parse_url($target, PHP_URL_PATH));
        $isDirectVideo = preg_match('/\.(?:mp4|m4v|webm|mov)$/', $path) === 1;

        return [
            'quality' => $quality ?: 'Automática',
            'language' => $language,
            'hls' => $isDirectVideo ? null : $target,
            'mp4' => $isDirectVideo ? $target : null,
            'proxy' => $this->proxyConfig($playlistId),
        ];
    }

    /** @return array{required: bool, proxies: array<int, array{id: string, name: string, base_url: string, priority: int}>} */
    private function proxyConfig(?string $playlistId): array
    {
        $playlists = $this->settings->get('iptv.vod_playlists', []);
        $playlist = is_array($playlists)
            ? collect($playlists)->first(fn (mixed $item): bool => is_array($item) && (string) ($item['id'] ?? '') === (string) $playlistId)
            : null;

        // Existing imported VOD records predate this setting. Keep them
        // protected until an administrator explicitly disables the proxy.
        $required = is_array($playlist) ? (bool) ($playlist['use_proxy'] ?? true) : true;

        return $this->proxyPool->playbackConfig($required);
    }

    private function usesLocalFixtures(): bool
    {
        return ! app()->environment('testing')
            && (bool) config('pixflix.catalog.use_fixtures', false);
    }

    private function fixtureStreams(?string $playlistId): array
    {
        return [[
            'quality' => '1080p',
            'language' => 'Latino',
            'hls' => (string) config(
                'pixflix.catalog.fixture_hls_url',
                'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
            ),
            'mp4' => null,
            'proxy' => $this->proxyConfig($playlistId),
        ]];
    }
}
