<?php

namespace App\Services\Catalog;

use App\Models\Episode;
use App\Models\Title;
use App\Services\SyncSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class StreamResolver
{
    public function __construct(
        private readonly PrincipalCatalogClient $catalog,
        private readonly StremioResolver $stremio,
        private readonly SyncSettings $settings,
    ) {}

    public function titleStreams(Title $title, ?string $language = null): array
    {
        if ($title->type !== 'movie') {
            return [];
        }

        $raw = $title->raw_extract ?? [];
        $language = $this->effectiveLanguage($language);
        $key = "title:{$title->id}";

        return $this->resolveInOrder(
            $key,
            $raw['streams'] ?? $title->getAttribute('streams') ?? [],
            fn (): array => $this->apiStreams($raw['extractUrl'] ?? $raw['extract_url'] ?? $raw['url'] ?? null),
            fn (): array => $this->stremio->forTitle($title, $language),
            $language,
            $title->slug,
        );
    }

    public function episodeStreams(Episode $episode, ?string $language = null): array
    {
        $language = $this->effectiveLanguage($language);

        return $this->resolveInOrder(
            "episode:{$episode->id}",
            $episode->streams,
            fn (): array => $this->apiStreams($episode->extract_url ?? $episode->url),
            fn (): array => $this->stremio->forEpisode($episode, $language),
            $language,
            "episode-{$episode->id}",
        );
    }

    public function resolve(array $payload): array
    {
        if (isset($payload['episode_id'])) {
            $episode = Episode::query()->find($payload['episode_id']);

            if ($episode !== null) {
                return $this->episodeStreams($episode, $payload['language'] ?? null);
            }
        }

        if (isset($payload['slug'])) {
            $title = Title::query()->where('slug', (string) $payload['slug'])->first();

            if ($title !== null) {
                return $this->titleStreams($title, $payload['language'] ?? null);
            }
        }

        return [];
    }

    private function normalize(array $stream): array
    {
        $hls = $stream['hls'] ?? $stream['proxyUrlHLS'] ?? $stream['proxyUrl'] ?? null;
        $mp4 = $stream['mp4'] ?? $stream['proxyUrlMP4'] ?? null;

        return [
            'quality' => $stream['quality'] ?? '1080p',
            'language' => $stream['language'] ?? $stream['lang'] ?? $stream['audio'] ?? 'Original',
            'hls' => $this->normalizeProxyUrl($hls),
            'mp4' => $this->normalizeProxyUrl($mp4),
        ];
    }

    private function resolveInOrder(
        string $key,
        mixed $cached,
        callable $api,
        callable $stremio,
        ?string $language,
        string $fixtureKey,
    ): array {
        if (! app()->environment('testing') && (bool) config('pixflix.catalog.use_fixtures', false)) {
            return $this->fixtureStreams($fixtureKey);
        }

        $languages = $language === null || trim($language) === '' ? null : $language;
        $cacheKey = $key.':'.sha1($languages ?? '*');

        if ($this->stremioIsPrimary()) {
            $stremioCacheKey = "pixflix:stremio:streams:{$cacheKey}";
            $cachedStremioStreams = $this->usable(Cache::get($stremioCacheKey), $languages);

            if ($cachedStremioStreams !== []) {
                return $cachedStremioStreams;
            }

            try {
                $addonStreams = $this->usable($stremio(), $languages);

                if ($addonStreams !== []) {
                    $this->remember($cacheKey, $addonStreams, true);

                    return $addonStreams;
                }
            } catch (Throwable $error) {
                Log::notice('Stremio primary resolution failed', ['key' => $key, 'error' => $error->getMessage()]);
            }

            return app()->environment('testing') || (bool) config('pixflix.catalog.use_fixtures', false)
                ? $this->fixtureStreams($fixtureKey)
                : [];
        }

        $cachedStreams = $this->usable($cached, $languages);

        if ($cachedStreams !== []) {
            return $cachedStreams;
        }

        $memoryCache = Cache::get("pixflix:streams:{$cacheKey}");
        $cachedStreams = $this->usable($memoryCache, $languages);

        if ($cachedStreams !== []) {
            return $cachedStreams;
        }

        try {
            $apiStreams = $this->usable($api(), $languages);

            if ($apiStreams !== []) {
                $this->remember($cacheKey, $apiStreams);

                return $apiStreams;
            }
        } catch (Throwable $error) {
            Log::notice('Playback API resolution failed', ['key' => $key, 'error' => $error->getMessage()]);
        }

        try {
            $addonStreams = $this->usable($stremio(), $languages);

            if ($addonStreams !== []) {
                $this->remember($cacheKey, $addonStreams);

                return $addonStreams;
            }
        } catch (Throwable $error) {
            Log::notice('Stremio fallback resolution failed', ['key' => $key, 'error' => $error->getMessage()]);
        }

        return app()->environment('testing') || (bool) config('pixflix.catalog.use_fixtures', false)
            ? $this->fixtureStreams($fixtureKey)
            : [];
    }

    private function stremioIsPrimary(): bool
    {
        return (bool) $this->settings->get(
            'stremio.primary',
            config('pixflix.stremio.primary', false),
        );
    }

    private function apiStreams(mixed $url): array
    {
        if (! is_string($url) || trim($url) === '') {
            return [];
        }

        $payload = $this->catalog->extract($url);

        return is_array($payload['streams'] ?? null) ? $payload['streams'] : [];
    }

    private function usable(mixed $streams, ?string $language): array
    {
        if (! is_array($streams) || ! array_is_list($streams)) {
            return [];
        }

        $wanted = $language === null || trim($language) === '' ? [] : $this->languageKeys($language);

        return collect($streams)
            ->filter(fn ($stream): bool => is_array($stream))
            ->map(fn (array $stream): array => $this->normalize($stream))
            ->filter(fn (array $stream): bool => $wanted === [] || in_array($this->languageKey($stream['language']), $wanted, true))
            ->filter(fn (array $stream): bool => $stream['hls'] !== null || $stream['mp4'] !== null)
            ->values()
            ->all();
    }

    private function languageKeys(string $language): array
    {
        return array_values(array_filter(array_map(
            fn (string $value): string => $this->languageKey($value),
            explode(',', $language),
        )));
    }

    private function effectiveLanguage(?string $language): ?string
    {
        if ($language !== null && trim($language) !== '') {
            return $language;
        }

        $configured = $this->settings->get('stremio.languages', config('pixflix.stremio.languages', []));

        if (is_array($configured)) {
            return implode(',', array_filter(array_map('strval', $configured))) ?: null;
        }

        return is_string($configured) && trim($configured) !== '' ? $configured : null;
    }

    private function languageKey(string $language): string
    {
        $value = strtolower(trim($language));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return match (true) {
            in_array($value, ['es', 'spa', 'spanish', 'espanol', 'castellano', 'latino', 'latam', 'es-419'], true) => 'spanish',
            in_array($value, ['en', 'eng', 'english', 'ingles'], true) => 'english',
            default => $value,
        };
    }

    private function remember(string $key, array $streams, bool $stremio = false): void
    {
        Cache::put(
            ($stremio ? 'pixflix:stremio:streams:' : 'pixflix:streams:').$key,
            $streams,
            max(60, (int) $this->settings->get('stremio.cache_ttl_seconds', config('pixflix.stremio.cache_ttl_seconds', 1800))),
        );
    }

    private function normalizeProxyUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        return preg_replace(
            '#^(https?://[^/]+)/{2,}(?=proxyvideo(?:[/?]|$))#i',
            '$1/',
            $url,
        ) ?: $url;
    }

    private function fixtureStreams(string $key): array
    {
        return [
            [
                'quality' => '1080p',
                'language' => 'Latino',
                'hls' => (string) config(
                    'pixflix.catalog.fixture_hls_url',
                    'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                ),
                'mp4' => null,
            ],
        ];
    }
}
