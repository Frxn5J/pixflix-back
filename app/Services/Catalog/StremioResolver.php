<?php

namespace App\Services\Catalog;

use App\Models\Episode;
use App\Models\Title;
use App\Services\SyncSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class StremioResolver
{
    public function __construct(private readonly SyncSettings $settings) {}

    public function forTitle(Title $title, ?string $language = null): array
    {
        return $this->resolve(
            'movie',
            $this->contentIds($title),
            $language,
            $title->title,
            $title->year,
        );
    }

    public function forEpisode(Episode $episode, ?string $language = null): array
    {
        $title = $episode->season?->title()->first();

        if ($title === null) {
            return [];
        }

        $season = $episode->season?->number;
        $number = $episode->number;

        if ($season === null || $number === null) {
            return [];
        }

        $ids = array_map(
            fn (string $id): string => "{$id}:{$season}:{$number}",
            $this->contentIds($title),
        );

        return $this->resolve('series', $ids, $language, $title->title, $title->year);
    }

    private function resolve(
        string $type,
        array $ids,
        ?string $requestedLanguage,
        ?string $searchTitle = null,
        ?string $searchYear = null,
    ): array {
        $enabled = $this->settings->get('stremio.streams_enabled', config('pixflix.stremio.streams_enabled'));
        if (! (bool) ($enabled ?? $this->settings->get('stremio.enabled', config('pixflix.stremio.enabled', false)))) {
            return [];
        }

        $addons = $this->streamAddons();
        $languages = $this->languages($requestedLanguage);

        foreach ($ids as $id) {
            foreach ($addons as $addon) {
                Log::debug('Stremio addon request', [
                    'addon' => $addon['name'],
                    'type' => $type,
                    'content_id' => $id,
                ]);

                $normalized = $this->requestStreams($addon, $type, $id, $languages);

                if ($normalized !== []) {
                    Log::info('Stremio addon selected', [
                        'addon' => $addon['name'],
                        'streams' => count($normalized),
                    ]);

                    return $normalized;
                }
            }
        }

        // Some catalogs (including the principal Pixflix catalog) do not keep
        // an IMDb id on every title. Search-enabled Stremio addons can still
        // resolve those titles to their canonical external id.
        if ($searchTitle !== null && trim($searchTitle) !== '') {
            foreach ($addons as $addon) {
                foreach ($this->searchContentIds($addon, $type, $searchTitle, $searchYear) as $id) {
                    if (in_array($id, $ids, true)) {
                        continue;
                    }

                    $normalized = $this->requestStreams($addon, $type, $id, $languages);

                    if ($normalized !== []) {
                        Log::info('Stremio addon selected after catalog search', [
                            'addon' => $addon['name'],
                            'content_id' => $id,
                            'streams' => count($normalized),
                        ]);

                        return $normalized;
                    }
                }
            }
        }

        return [];
    }

    private function requestStreams(array $addon, string $type, string $id, array $languages): array
    {
        $url = $this->streamUrl($addon['base_url'], $type, $id);

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout($addon))
                ->get($url);

            if (! $response->successful()) {
                Log::notice('Stremio addon unavailable', [
                    'addon' => $addon['name'],
                    'status' => $response->status(),
                    'content_id' => $id,
                ]);

                return [];
            }

            $payload = $response->json();
            $streams = is_array($payload) ? ($payload['streams'] ?? []) : [];

            return $this->usableStreams($streams, $languages);
        } catch (Throwable $error) {
            Log::warning('Stremio addon failed', [
                'addon' => $addon['name'],
                'content_id' => $id,
                'error' => $error->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array<int, string> */
    private function searchContentIds(array $addon, string $type, string $title, ?string $year): array
    {
        try {
            $manifestResponse = Http::acceptJson()
                ->timeout($this->timeout($addon))
                ->get($this->manifestUrl($addon['base_url']));

            if (! $manifestResponse->successful()) {
                return [];
            }

            $manifest = $manifestResponse->json();
            $catalogs = is_array($manifest) && is_array($manifest['catalogs'] ?? null)
                ? $manifest['catalogs']
                : [];

            foreach ($catalogs as $catalog) {
                if (! is_array($catalog) || $this->contentType((string) ($catalog['type'] ?? '')) !== $type) {
                    continue;
                }

                $catalogId = trim((string) ($catalog['id'] ?? ''));
                if ($catalogId === '' || ! $this->supportsSearch($catalog['extra'] ?? [])) {
                    continue;
                }

                $response = Http::acceptJson()
                    ->timeout($this->timeout($addon))
                    ->get($this->catalogSearchUrl($addon['base_url'], $type, $catalogId, $title));

                if (! $response->successful()) {
                    continue;
                }

                $payload = $response->json();
                $metas = is_array($payload) ? ($payload['metas'] ?? $payload['items'] ?? []) : [];
                if (! is_array($metas)) {
                    continue;
                }

                usort($metas, fn (mixed $left, mixed $right): int => $this->searchScore($right, $title, $year) <=> $this->searchScore($left, $title, $year));

                $ids = collect($metas)
                    ->filter(fn (mixed $meta): bool => is_array($meta))
                    ->map(fn (array $meta): string => trim((string) ($meta['id'] ?? $meta['imdb_id'] ?? $meta['imdbId'] ?? '')))
                    ->filter()
                    ->unique()
                    ->take(10)
                    ->values()
                    ->all();

                if ($ids !== []) {
                    return $ids;
                }
            }
        } catch (Throwable $error) {
            Log::notice('Stremio addon catalog search failed', [
                'addon' => $addon['name'],
                'title' => $title,
                'error' => $error->getMessage(),
            ]);
        }

        return [];
    }

    private function supportsSearch(mixed $extra): bool
    {
        if (! is_array($extra)) {
            return false;
        }

        return collect($extra)->contains(fn (mixed $item): bool => is_string($item) && strtolower(trim($item)) === 'search'
            || is_array($item) && strtolower(trim((string) ($item['name'] ?? ''))) === 'search');
    }

    private function searchScore(mixed $meta, string $title, ?string $year): int
    {
        if (! is_array($meta)) {
            return 0;
        }

        $score = 0;
        $candidate = $this->titleKey((string) ($meta['name'] ?? $meta['title'] ?? ''));
        $wanted = $this->titleKey($title);

        if ($candidate !== '' && $candidate === $wanted) {
            $score += 100;
        } elseif ($candidate !== '' && (str_contains($candidate, $wanted) || str_contains($wanted, $candidate))) {
            $score += 30;
        }

        if ($year !== null && preg_match('/\b'.preg_quote($year, '/').'\b/', json_encode($meta) ?: '') === 1) {
            $score += 10;
        }

        return $score;
    }

    private function titleKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    }

    private function streamAddons(): array
    {
        $configured = $this->settings->get('stremio.stream_addons', config('pixflix.stremio.stream_addons', []));
        if (! is_array($configured) || $configured === []) {
            $configured = $this->settings->get('stremio.addons', config('pixflix.stremio.addons', []));
        }

        if (! is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn ($addon): bool => is_array($addon)
                && ($addon['enabled'] ?? true) === true
                && filter_var($addon['base_url'] ?? null, FILTER_VALIDATE_URL))
            ->map(fn (array $addon): array => [
                'id' => (string) ($addon['id'] ?? $addon['base_url']),
                'name' => trim((string) ($addon['name'] ?? 'Addon Stremio')) ?: 'Addon Stremio',
                'base_url' => rtrim($this->withoutManifest((string) $addon['base_url']), '/'),
                'timeout_seconds' => max(1, min(60, (int) ($addon['timeout_seconds'] ?? $this->settings->get('stremio.timeout_seconds', config('pixflix.stremio.timeout_seconds', 10))))),
                'priority' => max(1, (int) ($addon['priority'] ?? 100)),
            ])
            ->sortBy('priority')
            ->values()
            ->all();
    }

    private function contentIds(Title $title): array
    {
        $raw = is_array($title->raw_extract) ? $title->raw_extract : [];
        $ids = [
            $raw['imdb_id'] ?? null,
            $raw['imdbId'] ?? null,
            $raw['imdb'] ?? null,
            $raw['id'] ?? null,
            $raw['stremio_id'] ?? null,
            $title->imdb_id,
            is_array($title->metadata) ? ($title->metadata['imdb_id'] ?? null) : null,
            is_array($title->metadata) ? ($title->metadata['imdbId'] ?? null) : null,
            $title->external_id,
            $title->slug,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn ($id): string => trim((string) $id),
            $ids,
        ), fn (string $id): bool => $id !== '')));
    }

    private function usableStreams(array $streams, array $languages): array
    {
        return collect($streams)
            ->filter(fn ($stream): bool => is_array($stream))
            ->filter(fn (array $stream): bool => ! $this->isDeadTorrent($stream))
            ->map(fn (array $stream): ?array => $this->normalize($stream))
            ->filter(fn (?array $stream): bool => $stream !== null)
            ->filter(fn (array $stream): bool => $languages === [] || $this->matchesLanguage($stream['language'], $languages))
            ->values()
            ->all();
    }

    private function normalize(array $stream): ?array
    {
        $url = $stream['url'] ?? null;
        $hls = $stream['hls'] ?? null;
        $mp4 = $stream['mp4'] ?? null;

        if (is_string($url) && preg_match('/\.m3u8(?:$|\?)/i', $url)) {
            $hls ??= $url;
        } elseif (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            $mp4 ??= $url;
        }

        $hls = $this->httpUrl($hls);
        $mp4 = $this->httpUrl($mp4);

        if ($hls === null && $mp4 === null) {
            return null;
        }

        return [
            'quality' => $this->quality($stream),
            'language' => $this->streamLanguage($stream),
            'hls' => $hls,
            'mp4' => $mp4,
        ];
    }

    private function isDeadTorrent(array $stream): bool
    {
        if (! $this->isTorrent($stream)) {
            return false;
        }

        $seeders = $this->number($stream, ['seeders', 'seeds', 'seed']);
        $leechers = $this->number($stream, ['leechers', 'leeches', 'peers', 'peerCount']);

        if ($seeders !== null || $leechers !== null) {
            return ($seeders ?? 0) <= 0 && ($leechers ?? 0) <= 0;
        }

        $text = strtolower($this->streamText($stream));
        $found = [];

        foreach ([
            '/(\d+)\s*(?:seeders?|seeds?)/i',
            '/(\d+)\s*(?:leechers?|leeches?|peers?)/i',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $found[] = (int) $matches[1];
            }
        }

        // A torrent without peer metadata cannot be proven healthy. Since the
        // player cannot recover a dead torrent, keep only torrents that expose
        // at least one seeder or leecher.
        return $found === [] || max($found) <= 0;
    }

    private function isTorrent(array $stream): bool
    {
        $text = strtolower($this->streamText($stream));

        return isset($stream['infoHash'])
            || isset($stream['infohash'])
            || str_starts_with((string) ($stream['url'] ?? ''), 'magnet:')
            || str_contains($text, 'torrent');
    }

    private function number(array $stream, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $stream[$key] ?? $stream['stats'][$key] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function streamText(array $stream): string
    {
        return implode(' ', array_filter([
            $stream['name'] ?? null,
            $stream['title'] ?? null,
            $stream['url'] ?? null,
            $stream['type'] ?? null,
            is_array($stream['sources'] ?? null) ? implode(' ', $stream['sources']) : ($stream['sources'] ?? null),
        ], 'is_string'));
    }

    private function streamLanguage(array $stream): string
    {
        foreach (['language', 'lang', 'audio'] as $key) {
            if (is_string($stream[$key] ?? null) && trim($stream[$key]) !== '') {
                return trim($stream[$key]);
            }
        }

        $text = strtolower($this->streamText($stream));

        foreach (['latino', 'español', 'spanish', 'castellano'] as $language) {
            if (str_contains($text, $language)) {
                return $language === 'latino' ? 'Latino' : 'Español';
            }
        }

        if (str_contains($text, 'english') || str_contains($text, 'ingles') || str_contains($text, 'inglés')) {
            return 'English';
        }

        return 'Original';
    }

    private function languages(?string $requestedLanguage): array
    {
        if ($requestedLanguage !== null && trim($requestedLanguage) !== '') {
            return $this->languageValues($requestedLanguage);
        }

        $configured = $this->settings->get('stremio.languages', config('pixflix.stremio.languages', []));

        return $this->languageValues($configured);
    }

    private function languageValues(array|string|null $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            fn ($language): string => $this->languageKey((string) $language),
            $values,
        )));
    }

    private function matchesLanguage(string $language, array $wanted): bool
    {
        $candidate = $this->languageKey($language);

        return in_array($candidate, $wanted, true)
            || ($candidate === 'spanish' && in_array('latino', $wanted, true))
            || ($candidate === 'latino' && in_array('spanish', $wanted, true));
    }

    private function languageKey(string $language): string
    {
        $value = strtolower(trim($language));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return match (true) {
            in_array($value, ['es', 'spa', 'spanish', 'espanol', 'castellano'], true) => 'spanish',
            in_array($value, ['latino', 'latam', 'es-419'], true) => 'latino',
            in_array($value, ['en', 'eng', 'english', 'ingles'], true) => 'english',
            in_array($value, ['original', 'vo', 'und', 'unknown'], true) => 'original',
            default => $value,
        };
    }

    private function quality(array $stream): string
    {
        foreach (['quality', 'resolution'] as $key) {
            if (is_string($stream[$key] ?? null) && trim($stream[$key]) !== '') {
                return trim($stream[$key]);
            }
        }

        if (preg_match('/\b(2160p|1440p|1080p|720p|480p|360p)\b/i', $this->streamText($stream), $matches)) {
            return $matches[1];
        }

        return 'Auto';
    }

    private function httpUrl(mixed $url): ?string
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }

    private function timeout(array $addon): int
    {
        return max(1, min(60, (int) $addon['timeout_seconds']));
    }

    private function streamUrl(string $baseUrl, string $type, string $id): string
    {
        $encodedId = str_replace('%3A', ':', rawurlencode($id));
        $parts = parse_url(trim($baseUrl));
        $suffix = '/stream/'.$type.'/'.$encodedId.'.json';

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($baseUrl, '/').$suffix;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = preg_replace('#/manifest(?:\.json)?$#i', '', $path) ?? $path;

        return $this->composeUrl($parts, rtrim($path, '/').$suffix);
    }

    private function manifestUrl(string $baseUrl): string
    {
        $parts = parse_url(trim($baseUrl));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim(trim($baseUrl), '/').'/manifest.json';
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/').'/manifest.json';

        return $this->composeUrl($parts, $path);
    }

    private function catalogSearchUrl(string $baseUrl, string $type, string $catalogId, string $title): string
    {
        $parts = parse_url(trim($baseUrl));
        $suffix = '/catalog/'.rawurlencode($type).'/'.rawurlencode($catalogId).'/search='.rawurlencode($title).'.json';

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($baseUrl, '/').$suffix;
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/').$suffix;

        return $this->composeUrl($parts, $path);
    }

    private function contentType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'movie' => 'movie',
            'series', 'tvshow' => 'series',
            default => null,
        };
    }

    private function withoutManifest(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return preg_replace('#/manifest(?:\.json)?$#i', '', trim($url)) ?: trim($url);
        }

        $path = preg_replace('#/manifest(?:\.json)?$#i', '', (string) ($parts['path'] ?? '')) ?? (string) ($parts['path'] ?? '');

        return $this->composeUrl($parts, rtrim($path, '/'));
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
}
