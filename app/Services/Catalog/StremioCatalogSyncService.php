<?php

namespace App\Services\Catalog;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Services\SyncSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StremioCatalogSyncService
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly SyncSettings $settings) {}

    /**
     * Import the active Stremio catalogs on the first catalog request. The
     * short-lived marker prevents every catalog endpoint from downloading the
     * addon catalogs again when they do not contain any items.
     */
    public function ensureAvailable(): void
    {
        if (! $this->isPrimary()) {
            return;
        }

        if (Cache::has($this->lastSyncKey())) {
            return;
        }

        $result = $this->sync();
        if (($result['errors'] ?? []) !== []) {
            Log::warning('Stremio catalog import completed with errors', [
                'errors' => $result['errors'],
            ]);
        }
    }

    public function invalidate(): void
    {
        Cache::forget($this->lastSyncKey());
        Cache::forever('pixflix:catalog-stamp', (string) now()->unix());
    }

    /**
     * @return array{status: string, addons: int, catalogs: int, titles: int, movies: int, series: int, episodes: int, deactivated: int, truncated: bool, errors: array<int, string>}
     */
    public function sync(bool $force = false): array
    {
        $empty = $this->emptyResult();

        if (! $this->isPrimary()) {
            return [...$empty, 'status' => 'disabled'];
        }

        if (! $force) {
            $cached = Cache::get($this->lastSyncKey());
            if (is_array($cached)) {
                return $cached;
            }
        }

        $lock = Cache::lock(
            'pixflix:sync:stremio-catalog',
            max(60, (int) config('pixflix.sync.lock_seconds', 3600)),
        );
        $acquired = false;

        try {
            if ($lock->get()) {
                $acquired = true;
            } else {
                $lock->block(min(120, max(30, (int) config('pixflix.stremio.catalog_sync_wait_seconds', 120))));
                $acquired = true;
            }

            if (! $force) {
                $cached = Cache::get($this->lastSyncKey());
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $result = $this->performSync();
            Cache::put(
                $this->lastSyncKey(),
                $result,
                max(60, (int) config('pixflix.stremio.catalog_sync_ttl_seconds', 900)),
            );

            return $result;
        } catch (LockTimeoutException) {
            return [...$empty, 'status' => 'locked'];
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /**
     * Resolve full metadata for a Stremio series only when the user opens it.
     * Catalog responses often contain no videos, while the meta endpoint does.
     */
    public function hydrateTitle(Title $title): void
    {
        if ($title->source !== 'stremio' || $title->type !== 'tvshow' || $title->seasons()->exists()) {
            return;
        }

        $raw = is_array($title->raw_extract) ? $title->raw_extract : [];
        $contentId = trim((string) ($raw['stremio_id'] ?? $title->external_id ?? ''));
        $contentId = preg_replace('/^stremio:(?:series|movie):/i', '', $contentId) ?: $contentId;
        if ($contentId === '') {
            return;
        }

        foreach ($this->addons() as $addon) {
            try {
                $response = Http::acceptJson()
                    ->timeout($this->timeout($addon))
                    ->get($this->endpoint($addon['base_url'], '/meta/series/'.rawurlencode($contentId).'.json'));
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            $meta = is_array($payload) && is_array($payload['meta'] ?? null)
                ? $payload['meta']
                : (is_array($payload) ? $payload : null);
            $videos = is_array($meta['videos'] ?? null) ? $this->videos($meta['videos']) : [];

            if ($meta === null || $videos === []) {
                continue;
            }

            DB::transaction(function () use ($title, $meta, $videos, $addon, $raw): void {
                $title->update([
                    'description' => $this->stringValue($meta, ['description']) ?? $title->description,
                    'poster' => $this->urlValue($meta['poster'] ?? null) ?? $title->poster,
                    'gallery' => $this->gallery($meta, $title->gallery ?? []),
                    'rating' => $this->stringValue($meta, ['imdbRating', 'rating']) ?? $title->rating,
                    'year' => $this->year($meta) ?? $title->year,
                    'genres' => $this->stringList($meta['genres'] ?? null) ?: ($title->genres ?? []),
                    'languages' => $this->stringList($meta['language'] ?? $meta['languages'] ?? null) ?: ($title->languages ?? []),
                    'total_seasons' => count(array_unique(array_filter(array_map(
                        fn (array $video): int => (int) ($video['season'] ?? 0),
                        array_filter($videos, 'is_array'),
                    )))) ?: $title->total_seasons,
                    'total_episodes' => count($videos),
                    'raw_extract' => [...$raw, 'detail_synced_at' => now()->toIso8601String()],
                ]);
                $this->syncVideos($title, $videos, $addon['id']);
            });

            Cache::forever('pixflix:catalog-stamp', (string) now()->unix());

            return;
        }
    }

    /** @return array<string, mixed> */
    private function performSync(): array
    {
        $result = $this->emptyResult();
        $items = [];
        $maxItems = max(1, (int) config('pixflix.stremio.catalog_max_items', 500));

        foreach ($this->addons() as $addon) {
            $result['addons']++;
            try {
                $manifestResponse = Http::acceptJson()
                    ->timeout($this->timeout($addon))
                    ->get($this->manifestUrl($addon['base_url']));
            } catch (Throwable $error) {
                $result['errors'][] = $addon['name'].': no fue posible descargar el manifest.';
                Log::notice('Stremio catalog manifest failed', ['addon' => $addon['name'], 'error' => $error->getMessage()]);

                continue;
            }

            if (! $manifestResponse->successful()) {
                $result['errors'][] = $addon['name'].": manifest HTTP {$manifestResponse->status()}.";

                continue;
            }

            $manifest = $manifestResponse->json();
            $catalogs = is_array($manifest) && is_array($manifest['catalogs'] ?? null)
                ? $manifest['catalogs']
                : [];

            foreach ($catalogs as $catalog) {
                if (! is_array($catalog)) {
                    continue;
                }

                $type = $this->contentType((string) ($catalog['type'] ?? ''));
                $catalogId = trim((string) ($catalog['id'] ?? ''));
                if ($type === null || $catalogId === '') {
                    continue;
                }

                $result['catalogs']++;
                $catalogFinished = false;
                $maxPages = max(1, (int) config('pixflix.stremio.catalog_max_pages', 10));

                for ($page = 0; $page < $maxPages; $page++) {
                    if (count($items) >= $maxItems) {
                        $result['truncated'] = true;
                        break;
                    }

                    $skip = $page * self::PAGE_SIZE;
                    try {
                        $catalogResponse = Http::acceptJson()
                            ->timeout($this->timeout($addon))
                            ->get($this->endpoint(
                                $addon['base_url'],
                                '/catalog/'.rawurlencode($type).'/'.rawurlencode($catalogId).($skip > 0 ? '/skip='.$skip : '').'.json',
                            ));
                    } catch (Throwable $error) {
                        $result['errors'][] = $addon['name'].": error al consultar el catálogo {$catalogId}.";
                        Log::notice('Stremio catalog request failed', ['addon' => $addon['name'], 'catalog' => $catalogId, 'error' => $error->getMessage()]);

                        break;
                    }

                    if (! $catalogResponse->successful()) {
                        $result['errors'][] = $addon['name'].": catálogo {$catalogId} HTTP {$catalogResponse->status()}.";

                        break;
                    }

                    $payload = $catalogResponse->json();
                    $metas = is_array($payload) ? ($payload['metas'] ?? $payload['items'] ?? []) : [];
                    if (! is_array($metas)) {
                        $result['errors'][] = $addon['name'].": catálogo {$catalogId} no devolvió metas.";

                        break;
                    }

                    foreach ($metas as $meta) {
                        if (count($items) >= $maxItems) {
                            $result['truncated'] = true;
                            break;
                        }
                        if (! is_array($meta)) {
                            continue;
                        }

                        $item = $this->normalizeMeta($meta, $type, $addon);
                        if ($item === null) {
                            continue;
                        }

                        $items[$item['external_id']] ??= $item;
                    }

                    if (count($metas) < self::PAGE_SIZE || $result['truncated']) {
                        $catalogFinished = true;
                        break;
                    }
                }

                if (! $catalogFinished && ! $result['truncated']) {
                    $result['truncated'] = true;
                }
                if ($result['truncated']) {
                    break;
                }
            }

            if ($result['truncated']) {
                break;
            }
        }

        if ($items !== [] || (! $result['truncated'] && $result['errors'] === [])) {
            DB::transaction(function () use ($items, &$result): void {
                $activeIds = [];
                foreach ($items as $item) {
                    $videos = $item['videos'];
                    unset($item['videos']);
                    $title = Title::query()->updateOrCreate(
                        ['external_id' => $item['external_id']],
                        $item,
                    );
                    $activeIds[] = $title->id;
                    $result['titles']++;
                    $result[$title->type === 'movie' ? 'movies' : 'series']++;

                    if ($videos !== []) {
                        $this->syncVideos($title, $videos, (string) $item['source_playlist_id']);
                        $result['episodes'] += count($videos);
                    }
                }

                if (! $result['truncated'] && $result['errors'] === []) {
                    $result['deactivated'] = Title::query()
                        ->where('source', 'stremio')
                        ->whereNotIn('id', $activeIds)
                        ->update(['is_active' => false, 'updated_at' => now()]);
                }
            });

            Cache::forever('pixflix:catalog-stamp', (string) now()->unix());
        }

        $result['status'] = $result['errors'] === [] ? 'success' : 'partial';

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function normalizeMeta(array $meta, string $type, array $addon): ?array
    {
        $id = trim((string) ($meta['id'] ?? $meta['imdb_id'] ?? $meta['imdbId'] ?? ''));
        $name = trim((string) ($meta['name'] ?? $meta['title'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }

        $externalId = 'stremio:'.$type.':'.$id;
        $videos = $type === 'series' && is_array($meta['videos'] ?? null) ? $this->videos($meta['videos']) : [];
        $poster = $this->urlValue($meta['poster'] ?? $meta['posterUrl'] ?? null);
        $background = $this->urlValue($meta['background'] ?? $meta['backgroundUrl'] ?? null);

        return [
            'external_id' => $externalId,
            'source' => 'stremio',
            'source_playlist_id' => (string) $addon['id'],
            'imdb_id' => preg_match('/^tt\d+$/i', $id) === 1 ? $id : null,
            'tmdb_id' => is_numeric($meta['tmdb_id'] ?? null) ? (int) $meta['tmdb_id'] : null,
            'is_active' => true,
            'slug' => Str::slug($name).'-'.substr(sha1($externalId), 0, 10),
            'type' => $type === 'series' ? 'tvshow' : 'movie',
            'title' => $name,
            'description' => $this->stringValue($meta, ['description', 'overview']),
            'poster' => $poster,
            'gallery' => $background !== null ? [$background] : [],
            'rating' => $this->stringValue($meta, ['imdbRating', 'rating']),
            'year' => $this->year($meta),
            'quality' => null,
            'languages' => $this->stringList($meta['language'] ?? $meta['languages'] ?? null),
            'genres' => $this->stringList($meta['genres'] ?? null),
            'category' => 'normal',
            'total_seasons' => $videos !== [] ? count(array_unique(array_column($videos, 'season'))) : null,
            'total_episodes' => $videos !== [] ? count($videos) : null,
            'raw_extract' => [
                'source' => 'stremio',
                'stremio_id' => $id,
                'stremio_type' => $type,
                'addon_id' => (string) $addon['id'],
                'imdb_id' => preg_match('/^tt\d+$/i', $id) === 1 ? $id : null,
            ],
            'stream_url' => null,
            'stream_headers' => null,
            'metadata' => [],
            'snapshot_version' => null,
            'videos' => $videos,
        ];
    }

    /** @param array<int, mixed> $videos */
    private function videos(array $videos): array
    {
        $normalized = [];
        foreach ($videos as $video) {
            if (! is_array($video)) {
                continue;
            }
            $season = (int) ($video['season'] ?? 0);
            $episode = (int) ($video['episode'] ?? 0);
            if ($season < 1 || $episode < 1) {
                continue;
            }
            $normalized[] = [
                'season' => $season,
                'episode' => $episode,
                'title' => trim((string) ($video['title'] ?? $video['name'] ?? 'Episodio '.$episode)) ?: 'Episodio '.$episode,
                'image' => $this->urlValue($video['thumbnail'] ?? $video['thumbnailUrl'] ?? null),
                'release_date' => isset($video['released']) && is_string($video['released']) ? $video['released'] : null,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $videos */
    private function syncVideos(Title $title, array $videos, string $sourcePlaylistId): void
    {
        if ($videos === []) {
            return;
        }

        $activeEpisodeIds = [];
        foreach (collect($videos)->groupBy('season') as $seasonNumber => $seasonVideos) {
            $season = Season::query()->updateOrCreate(
                ['title_id' => $title->id, 'number' => (int) $seasonNumber],
                ['title' => 'Temporada '.(int) $seasonNumber, 'release_date' => null],
            );
            foreach ($seasonVideos as $video) {
                $episode = Episode::query()->updateOrCreate(
                    ['season_id' => $season->id, 'number' => (int) $video['episode']],
                    [
                        'source' => 'stremio',
                        'source_playlist_id' => $sourcePlaylistId,
                        'is_active' => true,
                        'title' => $video['title'],
                        'url' => null,
                        'image' => $video['image'],
                        'release_date' => $video['release_date'],
                        'extract_url' => null,
                        'streams' => null,
                        'stream_url' => null,
                        'stream_headers' => null,
                    ],
                );
                $activeEpisodeIds[] = $episode->id;
            }
        }

        Episode::query()
            ->where('source', 'stremio')
            ->whereHas('season', fn ($query) => $query->where('title_id', $title->id))
            ->whereNotIn('id', $activeEpisodeIds)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'status' => 'success',
            'addons' => 0,
            'catalogs' => 0,
            'titles' => 0,
            'movies' => 0,
            'series' => 0,
            'episodes' => 0,
            'deactivated' => 0,
            'truncated' => false,
            'errors' => [],
        ];
    }

    private function isPrimary(): bool
    {
        return (bool) $this->settings->get('stremio.enabled', config('pixflix.stremio.enabled', false))
            && (bool) $this->settings->get('stremio.primary', config('pixflix.stremio.primary', false));
    }

    /** @return array<int, array<string, mixed>> */
    private function addons(): array
    {
        $configured = $this->settings->get('stremio.addons', config('pixflix.stremio.addons', []));

        return is_array($configured)
            ? collect($configured)
                ->filter(fn ($addon): bool => is_array($addon)
                    && ($addon['enabled'] ?? true) === true
                    && filter_var($addon['base_url'] ?? null, FILTER_VALIDATE_URL))
                ->map(fn (array $addon, int $index): array => [
                    'id' => trim((string) ($addon['id'] ?? 'addon-'.($index + 1))),
                    'name' => trim((string) ($addon['name'] ?? 'Addon Stremio')) ?: 'Addon Stremio',
                    'base_url' => trim((string) $addon['base_url']),
                    'timeout_seconds' => $this->timeout($addon),
                    'priority' => max(1, (int) ($addon['priority'] ?? 100)),
                ])
                ->sortBy('priority')
                ->values()
                ->all()
            : [];
    }

    private function timeout(array $addon): int
    {
        return max(1, min(60, (int) ($addon['timeout_seconds'] ?? $this->settings->get(
            'stremio.timeout_seconds',
            config('pixflix.stremio.timeout_seconds', 10),
        ))));
    }

    private function lastSyncKey(): string
    {
        return 'pixflix:stremio:catalog:last-sync';
    }

    private function contentType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'movie' => 'movie',
            'series', 'tvshow' => 'series',
            default => null,
        };
    }

    private function manifestUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim(trim($url), '/').'/manifest.json';
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/manifest(?:\.json)?$#i', $path)) {
            $path = preg_replace('#/manifest$#i', '/manifest.json', $path) ?: $path;
        } else {
            $path = rtrim($path, '/').'/manifest.json';
        }

        return $this->composeUrl($parts, $path);
    }

    private function endpoint(string $url, string $suffix): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim(trim($url), '/').$suffix;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#/manifest(?:\.json)?$#i', '', $path) ?? $path;

        return $this->composeUrl($parts, rtrim($path, '/').$suffix);
    }

    /** @param array<string, mixed> $parts */
    private function composeUrl(array $parts, string $path): string
    {
        $authority = (string) $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $parts['scheme'].'://'.$authority.$path
            .(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    /** @param array<string, mixed> $source */
    private function stringValue(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_scalar($source[$key] ?? null) && trim((string) $source[$key]) !== '') {
                return trim((string) $source[$key]);
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            fn ($item): string => trim((string) $item),
            $values,
        ))));
    }

    private function urlValue(mixed $value): ?string
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    private function year(array $meta): ?string
    {
        foreach (['year', 'releaseInfo', 'released'] as $key) {
            if (preg_match('/\b(19\d{2}|20\d{2})\b/', (string) ($meta[$key] ?? ''), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @param array<int, mixed> $fallback */
    private function gallery(array $meta, array $fallback): array
    {
        $background = $this->urlValue($meta['background'] ?? $meta['backgroundUrl'] ?? null);

        return $background !== null ? [$background] : $fallback;
    }
}
