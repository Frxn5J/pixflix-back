<?php

namespace App\Services\IptvVod;

use App\Models\Episode;
use App\Models\Title;
use App\Services\Streaming\StreamSigner;
use Illuminate\Http\Request;

class IptvVodPlayback
{
    public function __construct(private readonly StreamSigner $signer) {}

    public function titleStreams(Request $request, Title $title): ?array
    {
        if ($title->source !== 'iptv_vod') {
            return null;
        }

        if (! $title->is_active || $title->stream_url === null) {
            return [];
        }

        return [$this->streamData(
            $request,
            'title',
            $title->id,
            $title->stream_url,
            $title->quality,
            $title->languages[0] ?? 'Original',
            (array) $title->stream_headers,
        )];
    }

    public function episodeStreams(Request $request, Episode $episode): ?array
    {
        // Season has a `title` column too, so resolve the relationship explicitly.
        $title = $episode->season?->title()->first();
        if ($episode->source !== 'iptv_vod' && $title?->source !== 'iptv_vod') {
            return null;
        }

        if (! $episode->is_active || ! $title?->is_active || $episode->stream_url === null) {
            return [];
        }

        return [$this->streamData(
            $request,
            'episode',
            $episode->id,
            $episode->stream_url,
            $title->quality,
            $title->languages[0] ?? 'Original',
            (array) $episode->stream_headers,
        )];
    }

    public function proxyUrl(Request $request, string $kind, int $id, int $expires, string $target): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/')."/api/v1/vod/{$kind}/{$id}/stream?".http_build_query([
            'target' => $target,
            'expires' => $expires,
            'signature' => $this->signature($kind, $id, $expires, $target),
        ]);
    }

    public function valid(string $kind, int $id, int $expires, string $target, string $signature): bool
    {
        return $expires > now()->timestamp
            && $expires <= now()->addHours(13)->timestamp
            && hash_equals($this->signature($kind, $id, $expires, $target), $signature)
            && in_array(parse_url($target, PHP_URL_SCHEME), ['http', 'https'], true)
            && filter_var($target, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @param  array<string, string>  $upstreamHeaders
     */
    private function streamData(
        Request $request,
        string $kind,
        int $id,
        string $target,
        ?string $quality,
        string $language,
        array $upstreamHeaders = [],
    ): array {
        $expires = now()->addHours(12)->timestamp;
        $path = strtolower((string) parse_url($target, PHP_URL_PATH));
        $isDirectVideo = preg_match('/\.(?:mp4|m4v|webm|mov)$/', $path) === 1;

        // Direct video is raw bytes with no manifest to rewrite: hand it
        // straight to the external media proxy when one is configured.
        $mp4Url = null;
        if ($isDirectVideo) {
            $mp4Url = $this->signer->externalUrl($target, $expires, [
                'User-Agent' => 'Pixflix/1.0 IPTV VOD player',
                ...$upstreamHeaders,
            ]) ?? $this->proxyUrl($request, $kind, $id, $expires, $target);
        }

        return [
            'quality' => $quality ?: 'Automática',
            'language' => $language,
            'hls' => $isDirectVideo ? null : $this->proxyUrl($request, $kind, $id, $expires, $target),
            'mp4' => $mp4Url,
        ];
    }

    private function signature(string $kind, int $id, int $expires, string $target): string
    {
        return hash_hmac('sha256', "{$kind}|{$id}|{$expires}|{$target}", (string) config('app.key'));
    }
}
