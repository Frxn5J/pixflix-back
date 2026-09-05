<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EpgEntry;
use App\Services\Iptv\IptvProxyPool;
use App\Support\UrlSafety;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChannelController extends Controller
{
    public function __construct(
        private readonly IptvProxyPool $proxyPool,
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
        return sha1((string) Channel::query()->max('updated_at').':'.Channel::query()->count().':'.json_encode(
            $this->proxyPool->playbackConfig(false),
        ));
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
            'logo' => UrlSafety::http($channel->logo),
            'category' => $channel->category,
            'country' => $channel->country,
            'language' => $channel->language,
            'stream' => $channel->stream_url ? [
                'quality' => 'auto',
                'language' => $channel->language ?? 'original',
                // Media is fetched by the browser. The proxy decision and
                // available proxy URLs travel with the stream metadata.
                'hls' => $channel->stream_url,
                'mp4' => null,
                'headers' => $channel->stream_headers ?? [],
                'proxy' => $this->proxyPool->playbackConfig($channel->use_proxy),
            ] : null,
            'is_available' => $channel->stream_url !== null,
        ];
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
