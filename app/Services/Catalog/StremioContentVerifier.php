<?php

namespace App\Services\Catalog;

use App\Services\SyncSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

class StremioContentVerifier
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly SyncSettings $settings) {}

    public function verify(
        string $url,
        int $timeoutSeconds = 10,
        int $maxPages = 10,
        int $maxItems = 500,
        ?array $languages = null,
    ): array {
        $timeoutSeconds = max(1, min(60, $timeoutSeconds));
        $maxPages = max(1, min(100, $maxPages));
        $maxItems = max(1, min(5000, $maxItems));
        $manifestUrl = $this->manifestUrl($url);
        $languageFilter = $this->languageValues($languages ?? $this->settings->get(
            'stremio.languages',
            config('pixflix.stremio.languages', []),
        ));
        $startedAt = microtime(true);

        $report = [
            'valid' => false,
            'completed' => false,
            'truncated' => false,
            'manifest_url' => $manifestUrl,
            'manifest' => null,
            'limits' => [
                'max_pages' => $maxPages,
                'max_items' => $maxItems,
                'timeout_seconds' => $timeoutSeconds,
            ],
            'language_filter' => $languageFilter,
            'catalogs' => [],
            'totals' => $this->emptyTotals(),
            'streams' => $this->emptyStreamTotals(),
            'errors' => [],
            'warnings' => [],
        ];

        try {
            $response = Http::acceptJson()->timeout($timeoutSeconds)->get($manifestUrl);
        } catch (Throwable $error) {
            $report['errors'][] = 'No fue posible conectar con el manifest.';
            $report['technical_error'] = $error->getMessage();

            return $this->finish($report, $startedAt);
        }

        if (! $response->successful()) {
            $report['errors'][] = "El manifest respondió con HTTP {$response->status()}.";

            return $this->finish($report, $startedAt);
        }

        $manifest = $response->json();
        if (! is_array($manifest)) {
            $report['errors'][] = 'El manifest no contiene JSON válido.';

            return $this->finish($report, $startedAt);
        }

        $manifestErrors = [];
        foreach (['id', 'name', 'version'] as $field) {
            if (! is_string($manifest[$field] ?? null) || trim($manifest[$field]) === '') {
                $manifestErrors[] = "Falta el campo requerido {$field}.";
            }
        }

        $report['manifest'] = [
            'id' => $manifest['id'] ?? null,
            'name' => $manifest['name'] ?? null,
            'version' => $manifest['version'] ?? null,
        ];
        $report['valid'] = $manifestErrors === [];
        $report['errors'] = $manifestErrors;

        $catalogs = is_array($manifest['catalogs'] ?? null) ? $manifest['catalogs'] : [];
        if ($catalogs === []) {
            $report['warnings'][] = 'El manifest no declara catálogos verificables.';

            return $this->finish($report, $startedAt);
        }

        $items = [];
        foreach ($catalogs as $catalog) {
            if (count($items) >= $maxItems) {
                $report['truncated'] = true;
                break;
            }

            if (! is_array($catalog)) {
                continue;
            }

            $type = $this->contentType((string) ($catalog['type'] ?? ''));
            $catalogId = trim((string) ($catalog['id'] ?? ''));
            if ($type === null || $catalogId === '') {
                $report['warnings'][] = 'Se omitió un catálogo sin type o id compatible.';

                continue;
            }

            $catalogReport = [
                'type' => $type,
                'id' => $catalogId,
                'name' => $catalog['name'] ?? $catalogId,
                'items' => 0,
                'spanish_latino' => 0,
                'unknown_language' => 0,
                'pages' => 0,
                'truncated' => false,
                'errors' => [],
            ];
            $catalogItems = [];
            $catalogFinished = false;

            for ($page = 0; $page < $maxPages; $page++) {
                if (count($items) >= $maxItems) {
                    $catalogReport['truncated'] = true;
                    $report['truncated'] = true;
                    break;
                }

                $skip = $page * self::PAGE_SIZE;
                try {
                    $catalogResponse = Http::acceptJson()
                        ->timeout($timeoutSeconds)
                        ->get($this->catalogUrl($manifestUrl, $type, $catalogId, $skip));
                } catch (Throwable $error) {
                    $catalogReport['errors'][] = 'Error de red al consultar el catálogo.';
                    $catalogReport['technical_error'] = $error->getMessage();
                    break;
                }

                if (! $catalogResponse->successful()) {
                    $catalogReport['errors'][] = "El catálogo respondió con HTTP {$catalogResponse->status()}.";
                    break;
                }

                $payload = $catalogResponse->json();
                $metas = is_array($payload) ? ($payload['metas'] ?? $payload['items'] ?? []) : [];
                if (! is_array($metas)) {
                    $catalogReport['errors'][] = 'La respuesta del catálogo no contiene metas.';
                    break;
                }

                $catalogReport['pages']++;
                foreach ($metas as $meta) {
                    if (count($items) >= $maxItems) {
                        $catalogReport['truncated'] = true;
                        $report['truncated'] = true;
                        break 2;
                    }

                    if (! is_array($meta)) {
                        continue;
                    }

                    $id = trim((string) ($meta['id'] ?? $meta['imdb_id'] ?? $meta['imdbId'] ?? ''));
                    if ($id === '') {
                        continue;
                    }

                    $key = $type.':'.$id;
                    $languageStatus = $this->metadataLanguageStatus($meta);
                    if (! isset($catalogItems[$key])) {
                        $catalogItems[$key] = true;
                        $catalogReport['items']++;
                        if ($languageStatus === 'spanish_latino') {
                            $catalogReport['spanish_latino']++;
                        } elseif ($languageStatus === 'unknown') {
                            $catalogReport['unknown_language']++;
                        }
                    }

                    if (isset($items[$key])) {
                        continue;
                    }

                    $items[$key] = [
                        'type' => $type,
                        'id' => $id,
                        'language_status' => $languageStatus,
                    ];
                }

                if (count($metas) < self::PAGE_SIZE) {
                    $catalogFinished = true;
                    break;
                }
            }

            if ($catalogReport['pages'] >= $maxPages && ! $catalogFinished && ! $catalogReport['truncated']) {
                $catalogReport['truncated'] = true;
                $report['truncated'] = true;
            }

            $report['catalogs'][] = $catalogReport;
            $report['errors'] = array_values(array_merge($report['errors'], $catalogReport['errors']));
        }

        foreach ($items as $item) {
            $this->incrementItemTotals($report['totals'], $item['type'], $item['language_status']);
        }

        foreach ($items as $item) {
            $this->verifyStreams(
                $manifestUrl,
                $item,
                $timeoutSeconds,
                $languageFilter,
                $report['streams'],
            );
        }

        if ($report['truncated']) {
            $report['warnings'][] = 'La verificación alcanzó sus límites y el resultado es parcial.';
        }

        return $this->finish($report, $startedAt);
    }

    private function verifyStreams(
        string $manifestUrl,
        array $item,
        int $timeoutSeconds,
        array $languageFilter,
        array &$totals,
    ): void {
        $totals['items_checked']++;
        try {
            $response = Http::acceptJson()
                ->timeout($timeoutSeconds)
                ->get($this->streamUrl($manifestUrl, $item['type'], $item['id']));
        } catch (Throwable $error) {
            $totals['errors']++;

            return;
        }

        $totals['requests']++;
        if (! $response->successful()) {
            $totals['unavailable']++;

            return;
        }

        $payload = $response->json();
        $streams = is_array($payload) ? ($payload['streams'] ?? []) : [];
        if (! is_array($streams)) {
            $totals['unavailable']++;

            return;
        }

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $streamLanguage = $this->streamLanguage($stream);
            if ($languageFilter !== [] && ! $this->matchesLanguage($streamLanguage, $languageFilter)) {
                $totals['language_rejected']++;

                continue;
            }

            if ($this->isTorrent($stream)) {
                $peerState = $this->torrentPeerState($stream);
                if ($peerState === 'dead') {
                    $totals['dead_torrents']++;

                    continue;
                }
                if ($peerState === 'unverifiable') {
                    $totals['unverifiable_torrents']++;

                    continue;
                }

                $totals['healthy_torrents']++;
            }

            if ($this->playableUrl($stream) !== null) {
                $totals['playable']++;
            }
        }
    }

    private function emptyTotals(): array
    {
        return [
            'items' => 0,
            'movies' => 0,
            'series' => 0,
            'live' => 0,
            'spanish_latino' => 0,
            'spanish_latino_movies' => 0,
            'spanish_latino_series' => 0,
            'spanish_latino_live' => 0,
            'unknown_language' => 0,
        ];
    }

    private function emptyStreamTotals(): array
    {
        return [
            'items_checked' => 0,
            'requests' => 0,
            'playable' => 0,
            'healthy_torrents' => 0,
            'dead_torrents' => 0,
            'unverifiable_torrents' => 0,
            'language_rejected' => 0,
            'unavailable' => 0,
            'errors' => 0,
        ];
    }

    private function incrementItemTotals(array &$totals, string $type, string $languageStatus): void
    {
        $totals['items']++;
        $bucket = match ($type) {
            'movie' => 'movies',
            'series' => 'series',
            default => 'live',
        };
        if (array_key_exists($bucket, $totals)) {
            $totals[$bucket]++;
        }

        if ($languageStatus === 'spanish_latino') {
            $totals['spanish_latino']++;
            $key = 'spanish_latino_'.$bucket;
            if (array_key_exists($key, $totals)) {
                $totals[$key]++;
            }
        } elseif ($languageStatus === 'unknown') {
            $totals['unknown_language']++;
        }
    }

    private function metadataLanguageStatus(array $meta): string
    {
        $explicit = $this->textValues($meta, ['language', 'languages', 'audio', 'audioLanguage']);
        if ($this->containsSpanishSignal($explicit)) {
            return 'spanish_latino';
        }
        if ($explicit !== []) {
            return 'other';
        }

        $text = implode(' ', $this->textValues($meta, ['name', 'title', 'description', 'genre', 'genres']));

        return $this->containsSpanishSignal([$text]) ? 'spanish_latino' : 'unknown';
    }

    private function streamLanguage(array $stream): string
    {
        $values = $this->textValues($stream, ['language', 'lang', 'audio']);
        if ($values !== []) {
            return trim($values[0]);
        }

        $text = implode(' ', $this->textValues($stream, ['name', 'title', 'url', 'type', 'sources']));
        foreach (['latino', 'español', 'espanol', 'spanish', 'castellano', 'es-419'] as $signal) {
            if (stripos($text, $signal) !== false) {
                return $signal === 'latino' ? 'Latino' : 'Español';
            }
        }

        return 'Original';
    }

    private function containsSpanishSignal(array $values): bool
    {
        return preg_match('/latino|español|espanol|spanish|castellano|es-419/i', implode(' ', $values)) === 1;
    }

    private function languageValues(array|string|null $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(function ($language): string {
            $value = strtolower(trim((string) $language));
            $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

            return match (true) {
                in_array($value, ['es', 'spa', 'spanish', 'espanol', 'castellano'], true) => 'spanish',
                in_array($value, ['latino', 'latam', 'es-419'], true) => 'latino',
                in_array($value, ['en', 'eng', 'english', 'ingles'], true) => 'english',
                default => $value,
            };
        }, $values)));
    }

    private function matchesLanguage(string $language, array $wanted): bool
    {
        $candidate = $this->languageValues($language)[0] ?? '';

        return in_array($candidate, $wanted, true)
            || ($candidate === 'spanish' && in_array('latino', $wanted, true))
            || ($candidate === 'latino' && in_array('spanish', $wanted, true));
    }

    private function isTorrent(array $stream): bool
    {
        $text = strtolower(implode(' ', $this->textValues($stream, ['name', 'title', 'url', 'type', 'sources'])));

        return isset($stream['infoHash'])
            || isset($stream['infohash'])
            || str_starts_with((string) ($stream['url'] ?? ''), 'magnet:')
            || str_contains($text, 'torrent');
    }

    private function torrentPeerState(array $stream): string
    {
        $seeders = $this->number($stream, ['seeders', 'seeds', 'seed']);
        $leechers = $this->number($stream, ['leechers', 'leeches', 'peers', 'peerCount']);

        if ($seeders === null && $leechers === null) {
            $text = strtolower(implode(' ', $this->textValues($stream, ['name', 'title', 'url', 'type', 'sources'])));
            $values = [];
            foreach ([
                '/(\d+)\s*(?:seeders?|seeds?)/i',
                '/(\d+)\s*(?:leechers?|leeches?|peers?)/i',
            ] as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $values[] = (int) $matches[1];
                }
            }

            if ($values === []) {
                return 'unverifiable';
            }

            return max($values) > 0 ? 'healthy' : 'dead';
        }

        return ($seeders ?? 0) > 0 || ($leechers ?? 0) > 0 ? 'healthy' : 'dead';
    }

    private function number(array $stream, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $stream[$key] ?? (is_array($stream['stats'] ?? null) ? $stream['stats'][$key] ?? null : null);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function playableUrl(array $stream): ?string
    {
        foreach ([$stream['hls'] ?? null, $stream['mp4'] ?? null, $stream['url'] ?? null] as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return $url;
            }
        }

        return null;
    }

    private function textValues(array $source, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            } elseif (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_string($nested) && trim($nested) !== '') {
                        $values[] = trim($nested);
                    }
                }
            }
        }

        return $values;
    }

    private function contentType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'movie' => 'movie',
            'series', 'tvshow' => 'series',
            'channel' => 'channel',
            'tv' => 'tv',
            default => null,
        };
    }

    private function catalogUrl(string $manifestUrl, string $type, string $id, int $skip): string
    {
        $base = $this->baseUrl($manifestUrl);
        $path = '/catalog/'.rawurlencode($type).'/'.rawurlencode($id);
        if ($skip > 0) {
            $path .= '/skip='.$skip;
        }

        return $base.$path.'.json';
    }

    private function streamUrl(string $manifestUrl, string $type, string $id): string
    {
        return $this->baseUrl($manifestUrl).'/stream/'.rawurlencode($type).'/'.str_replace('%3A', ':', rawurlencode($id)).'.json';
    }

    private function manifestUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('#/manifest(?:\.json)?$#i', $url)) {
            return preg_replace('#/manifest$#i', '/manifest.json', $url) ?: $url;
        }

        return rtrim($url, '/').'/manifest.json';
    }

    private function baseUrl(string $manifestUrl): string
    {
        return preg_replace('#/manifest(?:\.json)?$#i', '', $manifestUrl) ?: $manifestUrl;
    }

    private function finish(array $report, float $startedAt): array
    {
        $report['completed'] = $report['errors'] === [] && $report['streams']['errors'] === 0;
        $report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $report;
    }
}
