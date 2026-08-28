<?php

namespace App\Services\Streaming;

use Illuminate\Http\Response;

/**
 * Decides how stream bytes reach the client.
 *
 * - `php`    : the PHP-FPM worker fetches the upstream and echoes it. Simple,
 *              but the worker stays busy for the whole transfer.
 * - `xaccel` : PHP only validates the signature and answers with an
 *              X-Accel-Redirect header; nginx fetches and streams the
 *              upstream with its event-driven model, freeing the worker
 *              in milliseconds. Requires the internal location defined in
 *              deploy/nginx-pixflix-api.conf.example.
 */
class StreamDelivery
{
    public function mode(): string
    {
        $mode = (string) config('pixflix.streaming.delivery', 'php');

        return in_array($mode, ['php', 'xaccel'], true) ? $mode : 'php';
    }

    public function isAccel(): bool
    {
        return $this->mode() === 'xaccel';
    }

    /**
     * Manifests (HLS playlists) must still be fetched and rewritten by PHP so
     * nested playlists and segments are re-signed. Everything else (segments,
     * direct video, live MPEG-TS) can be handed to nginx.
     */
    public function looksLikeManifest(string $target): bool
    {
        $path = strtolower((string) parse_url($target, PHP_URL_PATH));

        return str_ends_with($path, '.m3u8');
    }

    /**
     * Builds the empty response that instructs nginx to proxy $target through
     * the internal location. Only User-Agent and Referer may be forwarded to
     * the upstream; Range/If-Range travel with the client request itself.
     *
     * @param  array<string, string>  $upstreamHeaders
     */
    public function redirect(string $target, array $upstreamHeaders = []): Response
    {
        $query = ['target' => $target];

        foreach (['User-Agent' => 'ua', 'Referer' => 'referer'] as $header => $argument) {
            $value = trim((string) ($upstreamHeaders[$header] ?? ''));
            if ($value !== '') {
                $query[$argument] = $value;
            }
        }

        $location = rtrim((string) config('pixflix.streaming.accel_location', '/internal/upstream'), '/')
            .'?'.http_build_query($query);

        return response('', 200, [
            'X-Accel-Redirect' => $location,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
