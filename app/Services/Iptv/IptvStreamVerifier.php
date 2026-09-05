<?php

namespace App\Services\Iptv;

use App\Models\Channel;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class IptvStreamVerifier
{
    public function __construct(
        private readonly IptvProxyPool $proxyPool,
    ) {}

    /**
     * Verify the actual playback path for active or previously failed IPTV channels.
     *
     * A channel is considered healthy only when its manifest responds, the
     * first variant/segment responds, and the browser can access the response
     * through CORS. When a playlist requires a proxy, the configured proxy is
     * tested instead of the origin URL.
     *
     * @return array{status: string, checked: int, healthy: int, failed: int, deactivated: int, failures: array<string, int>}
     */
    public function run(?string $country = null, ?int $limit = null): array
    {
        if (! (bool) config('pixflix.iptv.verifier.enabled', true)) {
            return [
                'status' => 'disabled',
                'checked' => 0,
                'healthy' => 0,
                'failed' => 0,
                'deactivated' => 0,
                'failures' => [],
            ];
        }

        $query = Channel::query()
            ->where(function ($builder): void {
                $builder
                    ->where('is_active', true)
                    ->orWhere('stream_check_status', 'failed');
            })
            ->whereNotNull('stream_url')
            ->when($country !== null && trim($country) !== '', fn ($builder) => $builder->where('country', strtoupper(trim($country)))
            )
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $healthy = 0;
        $failed = 0;
        $deactivated = 0;
        $failures = [];

        foreach ($query->get() as $channel) {
            $checked++;
            $result = $this->verifyChannel($channel);
            $status = $result['healthy'] ? 'healthy' : 'failed';
            $error = $result['healthy'] ? null : $result['error'];

            $channel->forceFill([
                'stream_checked_at' => now(),
                'stream_check_status' => $status,
                'stream_check_error' => $error,
                'is_active' => $result['healthy'],
            ])->saveQuietly();

            if ($result['healthy']) {
                $healthy++;

                continue;
            }

            $failed++;
            $deactivated++;
            $failures[$error] = ($failures[$error] ?? 0) + 1;
        }

        return [
            'status' => 'completed',
            'checked' => $checked,
            'healthy' => $healthy,
            'failed' => $failed,
            'deactivated' => $deactivated,
            'failures' => $failures,
        ];
    }

    /** @return array{healthy: bool, error: string|null} */
    private function verifyChannel(Channel $channel): array
    {
        $target = $this->playbackTarget($channel);
        if ($target['error'] !== null) {
            return ['healthy' => false, 'error' => $target['error']];
        }

        $attempts = max(1, (int) config('pixflix.iptv.verifier.attempts', 2));
        $lastError = 'request_failed';

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $result = $this->probeManifest(
                    $target['origin_url'],
                    $target['request_url'],
                    $target['headers'],
                    $target['proxy'],
                    0,
                );

                if ($result['healthy']) {
                    return ['healthy' => true, 'error' => null];
                }

                $lastError = $result['error'] ?? $lastError;
            } catch (ConnectionException) {
                $lastError = 'timeout';
            } catch (Throwable) {
                $lastError = 'request_failed';
            }
        }

        return ['healthy' => false, 'error' => $lastError];
    }

    /**
     * @return array{origin_url: string, request_url: string, headers: array<string, string>, proxy: bool, error: string|null}
     */
    private function playbackTarget(Channel $channel): array
    {
        $originUrl = trim((string) $channel->stream_url);
        if (! filter_var($originUrl, FILTER_VALIDATE_URL)) {
            return [
                'origin_url' => $originUrl,
                'request_url' => $originUrl,
                'headers' => [],
                'proxy' => false,
                'error' => 'invalid_url',
            ];
        }

        $headers = collect(is_array($channel->stream_headers) ? $channel->stream_headers : [])
            ->only(['User-Agent', 'Referer'])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();

        if (! $channel->use_proxy && $headers !== []) {
            // A browser cannot reproduce arbitrary playlist headers on a
            // direct HLS request. Such entries need a proxy to be playable.
            return [
                'origin_url' => $originUrl,
                'request_url' => $originUrl,
                'headers' => $headers,
                'proxy' => false,
                'error' => 'direct_headers_unsupported',
            ];
        }

        if (! $channel->use_proxy) {
            return [
                'origin_url' => $originUrl,
                'request_url' => $originUrl,
                'headers' => $headers,
                'proxy' => false,
                'error' => null,
            ];
        }

        $proxy = $this->proxyPool->configured()[0] ?? null;
        if ($proxy === null) {
            return [
                'origin_url' => $originUrl,
                'request_url' => $originUrl,
                'headers' => $headers,
                'proxy' => true,
                'error' => 'proxy_not_configured',
            ];
        }

        $query = [
            'url' => $originUrl,
            'ua' => $headers['User-Agent'] ?? null,
            'referer' => $headers['Referer'] ?? null,
        ];

        return [
            'origin_url' => $originUrl,
            'request_url' => $this->appendQuery((string) $proxy['base_url'], $query),
            'headers' => [],
            'proxy' => true,
            'error' => null,
        ];
    }

    /** @return array{healthy: bool, error: string|null} */
    private function probeManifest(
        string $originUrl,
        string $requestUrl,
        array $headers,
        bool $throughProxy,
        int $depth,
    ): array {
        $response = $this->requestSample($requestUrl, $headers);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['healthy' => false, 'error' => 'http_'.$response['status']];
        }

        $corsError = $this->validateCors($response['cors']);
        if ($corsError !== null) {
            return ['healthy' => false, 'error' => $corsError];
        }

        $sample = trim($response['sample']);
        if ($sample === '') {
            return ['healthy' => false, 'error' => 'empty_response'];
        }

        $isHls = $this->isHls($originUrl, $response['content_type'], $sample);
        if (! $isHls) {
            return $this->validateMedia($response['content_type'], $sample);
        }

        if (! str_contains($sample, '#EXTM3U')) {
            return ['healthy' => false, 'error' => 'invalid_manifest'];
        }

        $reference = $this->firstHlsReference($sample);
        if ($reference === null) {
            return ['healthy' => false, 'error' => 'manifest_empty'];
        }

        $nextOriginUrl = $this->resolveUrl($originUrl, $reference);
        if ($nextOriginUrl === null) {
            return ['healthy' => false, 'error' => 'invalid_manifest_reference'];
        }

        $nextRequestUrl = $throughProxy
            ? $this->proxyUrlFor($nextOriginUrl, $requestUrl)
            : $nextOriginUrl;

        if ($depth >= 2) {
            return ['healthy' => true, 'error' => null];
        }

        return $this->probeManifest(
            $nextOriginUrl,
            $nextRequestUrl,
            $headers,
            $throughProxy,
            $depth + 1,
        );
    }

    /** @return array{status: int, sample: string, content_type: string, cors: string|null} */
    private function requestSample(string $url, array $headers): array
    {
        $response = null;

        try {
            $response = Http::withHeaders([
                'Accept' => '*/*',
                'Range' => 'bytes=0-131071',
                ...$headers,
            ])
                ->withOptions([
                    'allow_redirects' => true,
                    'stream' => true,
                    'verify' => (bool) config('pixflix.iptv.verify_ssl', true),
                ])
                ->connectTimeout((int) config('pixflix.iptv.verifier.connect_timeout_seconds', 5))
                ->timeout((int) config('pixflix.iptv.verifier.timeout_seconds', 10))
                ->get($url);

            $stream = $response->toPsrResponse()->getBody();
            $sample = (string) $stream->read((int) config('pixflix.iptv.verifier.sample_bytes', 131072));

            return [
                'status' => $response->status(),
                'sample' => $sample,
                'content_type' => strtolower((string) $response->header('Content-Type', '')),
                'cors' => $response->header('Access-Control-Allow-Origin'),
            ];
        } finally {
            $response?->close();
        }
    }

    /** @return array{healthy: bool, error: string|null} */
    private function validateMedia(string $contentType, string $sample): array
    {
        if ($sample === '') {
            return ['healthy' => false, 'error' => 'empty_media'];
        }

        if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/json')) {
            return ['healthy' => false, 'error' => 'invalid_media'];
        }

        return ['healthy' => true, 'error' => null];
    }

    private function validateCors(?string $cors): ?string
    {
        if (! (bool) config('pixflix.iptv.verifier.require_cors', true)) {
            return null;
        }

        return trim((string) $cors) === '' ? 'cors_missing' : null;
    }

    private function isHls(string $url, string $contentType, string $sample): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($path, '.m3u8')
            || str_contains($contentType, 'mpegurl')
            || str_contains($sample, '#EXTM3U');
    }

    private function firstHlsReference(string $manifest): ?string
    {
        $isMaster = str_contains($manifest, '#EXT-X-STREAM-INF');
        $nextIsVariant = false;
        $fallback = null;

        foreach (preg_split('/\r\n|\r|\n/', $manifest) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                $nextIsVariant = true;

                continue;
            }

            if (str_starts_with($line, '#EXT-X-MAP:')) {
                if (preg_match('/URI="([^"]+)"/i', $line, $matches) === 1) {
                    $fallback ??= $matches[1];
                }

                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            if ($isMaster && ! $nextIsVariant) {
                continue;
            }

            if ($nextIsVariant || ! $isMaster) {
                return $line;
            }
        }

        return $fallback;
    }

    private function resolveUrl(string $base, string $reference): ?string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri($reference));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, string|null> $query */
    private function appendQuery(string $baseUrl, array $query): string
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $baseUrl;
        }

        parse_str((string) ($parts['query'] ?? ''), $existing);
        foreach ($query as $key => $value) {
            if ($value !== null && $value !== '') {
                $existing[$key] = $value;
            }
        }

        $scheme = $parts['scheme'].'://';
        $authority = $parts['host'];
        if (isset($parts['user'])) {
            $authority = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'.$authority;
        }
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $scheme.$authority.($parts['path'] ?? '/').'?'.http_build_query($existing);
    }

    private function proxyUrlFor(string $originUrl, string $currentProxyUrl): string
    {
        $parts = parse_url($currentProxyUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $originUrl;
        }

        $proxyBase = $parts['scheme'].'://'.$parts['host'].($parts['port'] ?? null ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['url'] = $originUrl;

        return $proxyBase.'?'.http_build_query($query);
    }
}
