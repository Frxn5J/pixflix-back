<?php

namespace App\Services\IptvOrg;

use App\Models\Channel;
use App\Services\SyncSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IptvOrgSyncService
{
    public function __construct(
        private readonly IptvOrgClient $client,
        private readonly SyncSettings $settings,
    ) {}

    /**
     * @return array{channels: int, streams: int, deactivated: int}
     */
    public function run(?string $country = null, ?string $language = null, ?int $limit = null): array
    {
        $country = $this->cleanUpper($country);
        $language = $this->cleanLower($language);
        $limit = $limit !== null && $limit > 0 ? $limit : null;
        $rows = [];
        $seen = [];

        foreach ($this->configuredPlaylists() as $playlist) {
            if (! ($playlist['enabled'] ?? true)) {
                continue;
            }

            $playlistUrl = (string) $playlist['url'];
            $playlistCountry = $country
                ?? $this->cleanUpper($playlist['country'] ?? null)
                ?? $this->countryFromPlaylistUrl($playlistUrl);
            $playlistLanguage = $language ?? $this->cleanLower($playlist['language'] ?? null);

            foreach ($this->client->entries($playlistUrl) as $entry) {
                $id = $entry['external_id'];
                $entryCountry = $entry['country'] ?? $playlistCountry;
                $entryLanguage = $entry['language'] ?? $playlistLanguage;
                if (
                    isset($seen[$id])
                    || ! $this->matches($entryCountry, $playlistCountry, true)
                    || ! $this->matches($entryLanguage, $playlistLanguage, false)
                ) {
                    continue;
                }

                $seen[$id] = true;
                $rows[] = [
                    'external_id' => $id,
                    'name' => $entry['name'],
                    'logo' => $entry['logo'],
                    'category' => $entry['category'],
                    'country' => $entryCountry,
                    'language' => $entryLanguage,
                    'stream_url' => $entry['stream_url'],
                    // upsert bypasses Eloquent casts, so persist the JSON explicitly.
                    'stream_headers' => json_encode($entry['stream_headers'], JSON_THROW_ON_ERROR),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];

                if ($limit !== null && count($rows) >= $limit) {
                    break 2;
                }
            }
        }

        if ($rows === []) {
            throw new RuntimeException('Ninguna lista IPTV activa devolvio canales reproducibles para el filtro indicado.');
        }

        $shouldDeactivate = $limit === null;

        return DB::transaction(function () use ($rows, $shouldDeactivate, $country): array {
            foreach (array_chunk($rows, 500) as $chunk) {
                Channel::query()->upsert(
                    $chunk,
                    ['external_id'],
                    ['name', 'logo', 'category', 'country', 'language', 'stream_url', 'stream_headers', 'is_active', 'updated_at'],
                );
            }

            $deactivated = 0;
            if ($shouldDeactivate) {
                $activeIds = array_column($rows, 'external_id');
                $deactivated = Channel::query()
                    ->whereNotNull('external_id')
                    ->whereNotIn('external_id', $activeIds)
                    ->when($country !== null, fn ($query) => $query->where('country', $country))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            return [
                'channels' => count($rows),
                'streams' => count($rows),
                'deactivated' => $deactivated,
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function configuredPlaylists(): array
    {
        $playlists = $this->settings->get('iptv.playlists', null);

        if ($playlists === null) {
            return [[
                'url' => (string) config('pixflix.iptv.playlist_url'),
                'country' => config('pixflix.iptv.country'),
                'language' => null,
                'enabled' => true,
            ]];
        }

        return is_array($playlists) ? array_values(array_filter(
            $playlists,
            fn (mixed $playlist): bool => is_array($playlist) && ! empty($playlist['url']),
        )) : [];
    }

    private function matches(?string $value, ?string $filter, bool $upper): bool
    {
        if ($filter === null) {
            return true;
        }

        $values = array_filter(array_map(
            fn (string $item): string => $upper ? strtoupper(trim($item)) : strtolower(trim($item)),
            explode(',', $value ?? ''),
        ));
        $filters = array_filter(array_map(
            fn (string $item): string => $upper ? strtoupper(trim($item)) : strtolower(trim($item)),
            explode(',', $filter),
        ));

        return $filters === [] || array_intersect($values, $filters) !== [];
    }

    private function cleanUpper(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtoupper($value);
    }

    private function cleanLower(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtolower($value);
    }

    private function countryFromPlaylistUrl(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path !== '' ? $path : $url);

        return preg_match('/(?:^|[-_.])([a-z]{2})(?=[-_.]|$)/i', $filename, $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }
}
