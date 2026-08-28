<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\Title;
use App\Services\Iptv\IptvProxyPool;
use App\Services\IptvVod\IptvVodPlayback;
use App\Services\Streaming\StreamDelivery;
use App\Services\Streaming\StreamSigner;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class IptvVodStreamController extends Controller
{
    public function __construct(
        private readonly IptvProxyPool $proxyPool,
        private readonly IptvVodPlayback $playback,
        private readonly StreamDelivery $delivery,
        private readonly StreamSigner $signer,
    ) {}

    public function __invoke(Request $request, string $kind, int $id): Response|JsonResponse|StreamedResponse
    {
        $resource = $this->resource($kind, $id);
        $target = $request->query('target');
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        if (! is_string($target) || ! $this->playback->valid($kind, $id, $expires, $target, $signature)) {
            return response()->json(['error' => [
                'code' => 'invalid_stream_url',
                'message' => 'La URL del contenido VOD no es valida o ha expirado.',
            ]], 403);
        }

        $upstreamTarget = $this->proxyPool->unwrap($target);
        $headers = [
            'User-Agent' => 'Pixflix/1.0 IPTV VOD player',
            ...array_intersect_key((array) $resource->stream_headers, array_flip(['User-Agent', 'Referer'])),
        ];

        if ($this->delivery->isAccel() && ! $this->delivery->looksLikeManifest($upstreamTarget)) {
            // nginx forwards the client's Range/If-Range headers itself.
            return $this->delivery->redirect($this->proxyPool->wrap($upstreamTarget), $headers);
        }

        foreach (['Range', 'If-Range'] as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = (string) $request->header($header);
            }
        }

        try {
            $upstream = $this->proxyPool->fetch($upstreamTarget, 60, $headers, true);
        } catch (Throwable) {
            $upstream = null;
        }

        if (! $upstream instanceof ClientResponse || ! $upstream->successful()) {
            return response()->json(['error' => [
                'code' => 'stream_unavailable',
                'message' => 'El contenido VOD no esta disponible.',
            ]], 502);
        }

        $contentType = (string) ($upstream->header('Content-Type') ?: 'application/octet-stream');
        $isManifest = str_contains(strtolower($contentType), 'mpegurl')
            || str_ends_with(strtolower((string) parse_url($upstreamTarget, PHP_URL_PATH)), '.m3u8');

        if ($isManifest) {
            $body = $this->rewriteManifest(
                $request,
                $kind,
                $id,
                $expires,
                $upstream->body(),
                $upstreamTarget,
                array_intersect_key((array) $resource->stream_headers, array_flip(['User-Agent', 'Referer'])),
            );

            return response($body, 200, $this->corsHeaders([
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]));
        }

        $body = $upstream->toPsrResponse()->getBody();
        $responseHeaders = $this->corsHeaders([
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
        foreach (['Content-Length', 'Content-Range', 'Accept-Ranges', 'Last-Modified', 'ETag'] as $header) {
            $value = $upstream->header($header);
            if ($value !== null && $value !== '') {
                $responseHeaders[$header] = (string) $value;
            }
        }

        return response()->stream(function () use ($body): void {
            while (! $body->eof() && ! connection_aborted()) {
                echo $body->read(64 * 1024);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, $upstream->status(), $responseHeaders);
    }

    private function resource(string $kind, int $id): Title|Episode
    {
        if ($kind === 'title') {
            return Title::query()
                ->where('source', 'iptv_vod')
                ->where('is_active', true)
                ->findOrFail($id);
        }

        return Episode::query()
            ->where('source', 'iptv_vod')
            ->where('is_active', true)
            ->whereHas('season.title', fn ($query) => $query
                ->where('source', 'iptv_vod')
                ->where('is_active', true))
            ->findOrFail($id);
    }

    private function rewriteManifest(
        Request $request,
        string $kind,
        int $id,
        int $expires,
        string $manifest,
        string $baseUrl,
        array $upstreamHeaders = [],
    ): string {
        $manifest = preg_replace_callback('/URI="([^"]+)"/i', function (array $matches) use ($request, $kind, $id, $expires, $baseUrl, $upstreamHeaders): string {
            $target = $this->proxyPool->unwrap($this->resolveUrl($baseUrl, $matches[1]));

            return 'URI="'.$this->mediaUrl($request, $kind, $id, $expires, $target, $upstreamHeaders).'"';
        }, $manifest) ?? $manifest;

        $lines = preg_split('/\r?\n/', $manifest) ?: [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $target = $this->proxyPool->unwrap($this->resolveUrl($baseUrl, $trimmed));
            $lines[$index] = $this->mediaUrl($request, $kind, $id, $expires, $target, $upstreamHeaders);
        }

        return implode("\n", $lines);
    }

    /**
     * Routing per manifest entry: raw media bytes go to the external proxy
     * when configured; playlists stay on this server to keep being rewritten.
     */
    private function mediaUrl(Request $request, string $kind, int $id, int $expires, string $target, array $upstreamHeaders): string
    {
        if (! $this->delivery->looksLikeManifest($target)) {
            $external = $this->signer->externalUrl($target, $expires, [
                'User-Agent' => 'Pixflix/1.0 IPTV VOD player',
                ...$upstreamHeaders,
            ]);

            if ($external !== null) {
                return $external;
            }
        }

        return $this->playback->proxyUrl($request, $kind, $id, $expires, $target);
    }

    private function resolveUrl(string $baseUrl, string $reference): string
    {
        if (filter_var($reference, FILTER_VALIDATE_URL) !== false) {
            return $reference;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return $reference;
        }

        $prefix = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        if (str_starts_with($reference, '//')) {
            return $base['scheme'].':'.$reference;
        }
        if (str_starts_with($reference, '/')) {
            return $prefix.$reference;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath).'/';
        $path = $directory.$reference;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return $prefix.'/'.implode('/', $segments);
    }

    /** @param array<string, string> $headers */
    private function corsHeaders(array $headers): array
    {
        return $headers + [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => '*',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
        ];
    }
}
