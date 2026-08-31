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
        return $this->resolve('movie', $this->contentIds($title), $language);
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

        return $this->resolve('series', $ids, $language);
    }

    private function resolve(string $type, array $ids, ?string $requestedLanguage): array
    {
        if (! (bool) $this->settings->get('stremio.enabled', config('pixflix.stremio.enabled', false))) {
            return [];
        }

        $addons = $this->addons();
        $languages = $this->languages($requestedLanguage);

        foreach ($ids as $id) {
            foreach ($addons as $addon) {
                $url = $this->streamUrl($addon['base_url'], $type, $id);

                try {
                    Log::debug('Stremio addon request', [
                        'addon' => $addon['name'],
                        'type' => $type,
                        'content_id' => $id,
                    ]);

                    $response = Http::acceptJson()
                        ->timeout($this->timeout($addon))
                        ->get($url);

                    if (! $response->successful()) {
                        Log::notice('Stremio addon unavailable', [
                            'addon' => $addon['name'],
                            'status' => $response->status(),
                        ]);

                        continue;
                    }

                    $payload = $response->json();
                    $streams = is_array($payload) ? ($payload['streams'] ?? []) : [];
                    $normalized = $this->usableStreams($streams, $languages);

                    if ($normalized !== []) {
                        Log::info('Stremio addon selected', [
                            'addon' => $addon['name'],
                            'streams' => count($normalized),
                        ]);

                        return $normalized;
                    }
                } catch (Throwable $error) {
                    Log::warning('Stremio addon failed', [
                        'addon' => $addon['name'],
                        'error' => $error->getMessage(),
                    ]);
                }
            }
        }

        return [];
    }

    private function addons(): array
    {
        $configured = $this->settings->get('stremio.addons', config('pixflix.stremio.addons', []));

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
