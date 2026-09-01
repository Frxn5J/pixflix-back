<?php

namespace App\Services\Catalog;

use App\Models\Episode;
use App\Models\Title;
use App\Services\SyncSettings;
use Illuminate\Support\Facades\Cache;

class StreamResolver
{
    public function __construct(
        private readonly StremioResolver $stremio,
        private readonly SyncSettings $settings,
    ) {}

    public function titleStreams(Title $title, ?string $language = null): array
    {
        if ($title->type !== 'movie') {
            return [];
        }

        $language = $this->effectiveLanguage($language);
        $key = "title:{$title->id}";

        return $this->resolveFromVodAddon(
            $key,
            fn (): array => $this->stremio->forTitle($title, $language),
            $language,
        );
    }

    public function episodeStreams(Episode $episode, ?string $language = null): array
    {
        $language = $this->effectiveLanguage($language);

        return $this->resolveFromVodAddon(
            "episode:{$episode->id}",
            fn (): array => $this->stremio->forEpisode($episode, $language),
            $language,
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

    private function resolveFromVodAddon(
        string $key,
        callable $stremio,
        ?string $language,
    ): array {
        $languages = $language === null || trim($language) === '' ? null : $language;
        $cacheKey = $key.':'.sha1($languages ?? '*');

        $cachedStreams = $this->usable(Cache::get("pixflix:stremio:vod:streams:{$cacheKey}"), $languages);

        if ($cachedStreams !== []) {
            return $cachedStreams;
        }
        $addonStreams = $this->usable($stremio(), $languages);
        if ($addonStreams !== []) {
            $this->remember($cacheKey, $addonStreams);
        }

        return $addonStreams;
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

    private function remember(string $key, array $streams): void
    {
        Cache::put(
            'pixflix:stremio:vod:streams:'.$key,
            $streams,
            max(60, (int) config('pixflix.stremio.cache_ttl_seconds', 1800)),
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

}
