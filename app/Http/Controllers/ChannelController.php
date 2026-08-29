<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EpgEntry;
use App\Services\Iptv\IptvProxyPool;
use App\Services\Streaming\StreamDelivery;
use App\Services\Streaming\StreamSigner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ChannelController extends Controller
{
    public function __construct(
        private readonly IptvProxyPool $proxyPool,
        private readonly StreamDelivery $delivery,
        private readonly StreamSigner $signer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ttl = (int) config('pixflix.cache.channels_ttl', 30);
        $filters = $request->only(['category', 'country', 'language', 'q']);

        $payload = $this->rememberChannels('index:'.$this->channelStamp().':'.sha1((string) json_encode($filters)), $ttl, function () use ($request): array {
            $channels = $this->activeChannels($request)->orderBy('name')->get();

            return [
                'data' => $channels->map(fn (Channel $channel) => $this->channelData($request, $channel))->values()->all(),
                'meta' => ['total' => $channels->count()],
            ];
        });

        return response()->json($payload);
    }

    /**
     * Warm the unfiltered live-channel payloads used by the home/live views.
     * This is invoked internally by the post-deploy cache job.
     *
     * @return array<string, mixed>
     */
    public function warmCache(): array
    {
        $request = Request::create('/api/v1/channels', 'GET');
        $this->index($request);
        $this->now($request);

        return [
            'index' => true,
            'now' => true,
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $channel = Channel::query()->where('is_active', true)->findOrFail($id);

        return response()->json(['data' => [
            ...$this->channelData($request, $channel),
            'current' => $this->currentProgram($channel),
            'next' => $this->nextProgram($channel),
        ]]);
    }

    public function epg(Request $request, int $id): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => [
                'code' => 'validation_error',
                'message' => 'La fecha no es valida.',
            ]], 422);
        }

        $channel = Channel::query()->where('is_active', true)->findOrFail($id);
        $entries = $channel->epgEntries()
            ->whereDate('start_at', $date)
            ->orderBy('start_at')
            ->get()
            ->map(fn (EpgEntry $entry) => $this->epgData($entry))
            ->values();

        return response()->json(['data' => $entries, 'meta' => ['date' => $date]]);
    }

    public function now(Request $request): JsonResponse
    {
        $ttl = min((int) config('pixflix.cache.channels_ttl', 30), 30);
        $filters = $request->only(['category', 'country', 'language', 'q']);
        $key = 'now:'.$this->channelStamp().':'.sha1((string) json_encode($filters));

        $payload = $this->rememberChannels($key, $ttl, fn (): array => $this->nowPayload($request));

        return response()->json($payload);
    }

    private function nowPayload(Request $request): array
    {
        $channels = $this->activeChannels($request)->orderBy('name')->get();
        $availableCategories = Channel::query()
            ->where('is_active', true)
            ->when($request->filled('country'), fn (Builder $query) => $query->where('country', $request->string('country')))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();
        $availableCountries = Channel::query()
            ->where('is_active', true)
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country')
            ->values();
        $now = now();
        $programsByChannel = EpgEntry::query()
            ->whereIn('channel_id', $channels->modelKeys())
            ->where('end_at', '>', $now)
            ->orderBy('start_at')
            ->get()
            ->groupBy('channel_id');

        return [
            'data' => $channels->map(fn (Channel $channel) => [
                ...$this->channelData($request, $channel),
                'current' => $this->programData($programsByChannel->get($channel->id, collect())->first(
                    fn (EpgEntry $entry): bool => $entry->start_at <= $now && $entry->end_at > $now,
                )),
                'next' => $this->programData($programsByChannel->get($channel->id, collect())->first(
                    fn (EpgEntry $entry): bool => $entry->start_at > $now,
                )),
            ])->values()->all(),
            'meta' => [
                'total' => $channels->count(),
                'categories' => $availableCategories,
                'countries' => $availableCountries,
            ],
        ];
    }

    public function stream(Request $request, int $id): Response|JsonResponse
    {
        $channel = Channel::query()->where('is_active', true)->findOrFail($id);
        $target = $request->query('target');
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        if (! is_string($target) || ! $this->validProxySignature($id, $expires, $target, $signature)) {
            return response()->json(['error' => [
                'code' => 'invalid_stream_url',
                'message' => 'La URL del stream no es valida o ha expirado.',
            ]], 403);
        }

        $upstreamTarget = $this->proxyPool->unwrap($target);
        $upstreamHeaders = $this->upstreamHeaders($channel);

        if ($this->delivery->isAccel() && ! $this->delivery->looksLikeManifest($upstreamTarget)) {
            return $this->delivery->redirect($this->proxyPool->wrap($upstreamTarget), $upstreamHeaders);
        }

        try {
            $upstream = $this->proxyPool->fetch($upstreamTarget, 20, $upstreamHeaders);
        } catch (Throwable) {
            return response()->json(['error' => [
                'code' => 'stream_unavailable',
                'message' => 'El proveedor IPTV no responde.',
            ]], 502);
        }

        if (! $upstream instanceof \Illuminate\Http\Client\Response || ! $upstream->successful()) {
            return response()->json(['error' => [
                'code' => 'stream_unavailable',
                'message' => 'El stream IPTV no esta disponible.',
            ]], 502);
        }

        $body = $upstream->body();
        $contentType = (string) ($upstream->header('Content-Type') ?: 'application/octet-stream');
        $isManifest = str_contains(strtolower($contentType), 'mpegurl')
            || str_ends_with(strtolower((string) parse_url($upstreamTarget, PHP_URL_PATH)), '.m3u8')
            || str_contains($body, '#EXTM3U');

        if ($isManifest) {
            $body = $this->rewriteManifest($request, $channel, $expires, $body, $upstreamTarget);
            $contentType = 'application/vnd.apple.mpegurl';
        }

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => '*',
        ]);
    }

    /**
     * Caches hot channel payloads. The stamp embeds the latest channel
     * mutation so admin toggles and IPTV syncs invalidate automatically.
     */
    private function rememberChannels(string $key, int $ttl, callable $producer): array
    {
        if ($ttl <= 0) {
            return $producer();
        }

        return Cache::remember('pixflix:channels:'.$key, $ttl, $producer);
    }

    private function channelStamp(): string
    {
        return sha1((string) Channel::query()->max('updated_at').':'.Channel::query()->count());
    }

    private function activeChannels(Request $request): Builder
    {
        return Channel::query()
            ->where('is_active', true)
            ->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')))
            ->when($request->filled('country'), fn (Builder $query) => $query->where('country', $request->string('country')))
            ->when($request->filled('language'), fn (Builder $query) => $query->where('language', $request->string('language')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $q = trim($request->string('q')->toString());
                $query->where('name', 'like', "%{$q}%");
            });
    }

    private function currentProgram(Channel $channel): ?array
    {
        $entry = $channel->epgEntries()
            ->where('start_at', '<=', now())
            ->where('end_at', '>', now())
            ->orderByDesc('start_at')
            ->first();

        return $entry ? $this->epgData($entry) : null;
    }

    private function nextProgram(Channel $channel): ?array
    {
        $entry = $channel->epgEntries()
            ->where('start_at', '>', now())
            ->orderBy('start_at')
            ->first();

        return $entry ? $this->epgData($entry) : null;
    }

    private function channelData(Request $request, Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'logo' => $channel->logo,
            'category' => $channel->category,
            'country' => $channel->country,
            'language' => $channel->language,
            'stream' => $channel->stream_url ? [
                'quality' => 'auto',
                'language' => $channel->language ?? 'original',
                'hls' => $this->streamProxyUrl($request, $channel),
                'mp4' => null,
            ] : null,
            'is_available' => $channel->stream_url !== null,
        ];
    }

    private function streamProxyUrl(Request $request, Channel $channel): ?string
    {
        if ($channel->stream_url === null) {
            return null;
        }

        if (! $channel->use_proxy) {
            return $channel->stream_url;
        }

        $expires = now()->addHours(2)->timestamp;
        $target = $this->proxyPool->unwrap($channel->stream_url);

        // Raw live bytes (MPEG-TS, not a playlist) can go straight to the
        // external media proxy when one is configured.
        if (! $this->delivery->looksLikeManifest($target)) {
            $external = $this->signer->externalUrl($target, $expires, $this->upstreamHeaders($channel));

            if ($external !== null) {
                return $external;
            }
        }

        return $this->proxyUrlForTarget($request, $channel->id, $expires, $target);
    }

    /** @return array<string, string> */
    private function upstreamHeaders(Channel $channel): array
    {
        return [
            'User-Agent' => 'Pixflix/1.0 IPTV player',
            ...array_intersect_key((array) $channel->stream_headers, array_flip(['User-Agent', 'Referer'])),
        ];
    }

    private function validProxySignature(int $channelId, int $expires, string $target, string $signature): bool
    {
        return $expires > now()->timestamp
            && $expires <= now()->addHours(3)->timestamp
            && hash_equals($this->proxySignature($channelId, $expires, $target), $signature)
            && in_array(parse_url($target, PHP_URL_SCHEME), ['http', 'https'], true)
            && filter_var($target, FILTER_VALIDATE_URL) !== false;
    }

    private function proxySignature(int $channelId, int $expires, string $target): string
    {
        return hash_hmac('sha256', "{$channelId}|{$expires}|{$target}", (string) config('app.key'));
    }

    private function rewriteManifest(Request $request, Channel $channel, int $expires, string $manifest, string $baseUrl): string
    {
        $manifest = preg_replace_callback('/URI="([^"]+)"/i', function (array $matches) use ($request, $channel, $expires, $baseUrl): string {
            $target = $this->proxyPool->unwrap($this->resolveUrl($baseUrl, $matches[1]));

            return 'URI="'.$this->mediaUrl($request, $channel, $expires, $target).'"';
        }, $manifest) ?? $manifest;

        $lines = preg_split('/\r?\n/', $manifest) ?: [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $target = $this->proxyPool->unwrap($this->resolveUrl($baseUrl, $trimmed));
            $lines[$index] = $this->mediaUrl($request, $channel, $expires, $target);
        }

        return implode("\n", $lines);
    }

    /**
     * Routing per manifest entry: raw media bytes (segments) go to the
     * external proxy when configured; playlists stay on this server so they
     * keep being rewritten. Without an external proxy everything stays local.
     */
    private function mediaUrl(Request $request, Channel $channel, int $expires, string $target): string
    {
        if (! $this->delivery->looksLikeManifest($target)) {
            $external = $this->signer->externalUrl($target, $expires, $this->upstreamHeaders($channel));

            if ($external !== null) {
                return $external;
            }
        }

        return $this->proxyUrlForTarget($request, $channel->id, $expires, $target);
    }

    private function proxyUrlForTarget(Request $request, int $channelId, int $expires, string $target): string
    {
        return rtrim($request->getSchemeAndHttpHost(), '/')."/api/v1/channels/{$channelId}/stream?".http_build_query([
            'target' => $target,
            'expires' => $expires,
            'signature' => $this->proxySignature($channelId, $expires, $target),
        ]);
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
        if (str_starts_with($reference, '?')) {
            return $prefix.($base['path'] ?? '/').$reference;
        }
        if (str_starts_with($reference, '/')) {
            return $prefix.$this->normalizePath($reference);
        }

        return $prefix.$this->normalizePath(dirname($base['path'] ?? '/').'/'.$reference);
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function epgData(EpgEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'description' => $entry->description,
            'start_at' => $entry->start_at?->toIso8601String(),
            'end_at' => $entry->end_at?->toIso8601String(),
        ];
    }

    private function programData(?EpgEntry $entry): ?array
    {
        return $entry ? $this->epgData($entry) : null;
    }
}
