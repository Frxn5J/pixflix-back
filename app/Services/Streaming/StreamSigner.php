<?php

namespace App\Services\Streaming;

/**
 * Signs stream URLs for the external media proxy (Cloudflare Worker).
 *
 * Scheme: HMAC-SHA256 over "stream|{expires}|{target}" with a dedicated
 * shared secret (PIXFLIX_STREAM_PROXY_SECRET, falling back to app.key).
 * This is intentionally a SEPARATE scheme from the backend's own stream
 * endpoint signatures (which use app.key over kind|id|expires|target), so
 * the Worker can only ever serve URLs minted for it.
 */
class StreamSigner
{
    public function externalBaseUrl(): ?string
    {
        $base = rtrim(trim((string) config('pixflix.streaming.proxy_base_url', '')), '/');

        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $base;
    }

    public function externalEnabled(): bool
    {
        return $this->externalBaseUrl() !== null;
    }

    public function secret(): string
    {
        return (string) (config('pixflix.streaming.proxy_secret') ?: config('app.key'));
    }

    public function signExternal(string $target, int $expires): string
    {
        return hash_hmac('sha256', "stream|{$expires}|{$target}", $this->secret());
    }

    /**
     * URL the player should fetch for raw media bytes. Returns null when no
     * external proxy is configured (caller falls back to self-hosted URLs).
     *
     * @param  array<string, string>  $upstreamHeaders
     */
    public function externalUrl(string $target, int $expires, array $upstreamHeaders = []): ?string
    {
        $base = $this->externalBaseUrl();

        if ($base === null) {
            return null;
        }

        // Normalize "https://worker.dev" to "https://worker.dev/" so query
        // strings attach to a proper path.
        if ((string) parse_url($base, PHP_URL_PATH) === '') {
            $base .= '/';
        }

        $query = [
            'target' => $target,
            'expires' => $expires,
            'signature' => $this->signExternal($target, $expires),
        ];

        foreach (['User-Agent' => 'ua', 'Referer' => 'referer'] as $header => $argument) {
            $value = trim((string) ($upstreamHeaders[$header] ?? ''));
            if ($value !== '') {
                $query[$argument] = $value;
            }
        }

        return $base.'?'.http_build_query($query);
    }
}
