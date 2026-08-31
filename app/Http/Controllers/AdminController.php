<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshIptvResourcesJob;
use App\Jobs\SyncIptvJob;
use App\Jobs\SyncIptvVodJob;
use App\Models\Channel;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Catalog\StremioAddonVerifier;
use App\Services\Catalog\StremioCatalogSyncService;
use App\Services\Catalog\StremioContentVerifier;
use App\Services\Iptv\IptvProxyPool;
use App\Services\Iptv\IptvResourceSyncService;
use App\Services\IptvOrg\IptvOrgSyncService;
use App\Services\IptvVod\IptvVodSyncService;
use App\Services\SyncSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function __construct(private readonly SyncSettings $settings) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'users' => User::query()->count(),
            'subscribers' => User::query()->where('role', 'subscriber')->count(),
            'staff' => User::query()->whereIn('role', ['admin', 'agent'])->count(),
            'active_subscriptions' => Subscription::query()->whereIn('status', Subscription::ACCESSIBLE_STATUSES)->count(),
            'trials' => Subscription::query()->where('is_trial', true)->where('status', 'trial')->count(),
            'plans' => Plan::query()->count(),
            'active_plans' => Plan::query()->where('is_active', true)->count(),
            'channels' => Channel::query()->count(),
            'active_channels' => Channel::query()->where('is_active', true)->count(),
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $users = User::query()
            ->with(['subscriptions' => fn ($query) => $query->latest('id')->with('plan')])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim($request->string('q')->toString());
                $query->where(function ($nested) use ($term): void {
                    $nested->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user) => $this->userData($user))->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', Rule::unique('users', 'phone')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'alpha_dash', 'max:60', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['admin', 'agent', 'subscriber'])],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:120'],
        ]);

        if (array_key_exists('password', $validated) && $validated['password'] === null) {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json(['data' => $this->userData($user->refresh()->load(['subscriptions' => fn ($query) => $query->latest('id')->with('plan')]))]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $subscriptions = Subscription::query()
            ->with(['user', 'plan'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim($request->string('q')->toString());
                $query->whereHas('user', function ($userQuery) use ($term): void {
                    $userQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('username', 'like', "%{$term}%");
                });
            })
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($subscriptions->items())->map(fn (Subscription $subscription) => $this->subscriptionData($subscription))->values(),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    public function updateSubscription(Request $request, int $id): JsonResponse
    {
        $subscription = Subscription::query()->findOrFail($id);
        $validated = $request->validate([
            'plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'expiring', 'expired', 'suspended', 'cancelled', 'trial'])],
            'is_trial' => ['sometimes', 'boolean'],
            'group_number' => ['sometimes', 'integer', 'between:1,7'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'trial_expires_at' => ['sometimes', 'nullable', 'date'],
            'custom_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        $subscription->update($validated);

        return response()->json(['data' => $this->subscriptionData($subscription->refresh()->load(['user', 'plan']))]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->withCount('subscriptions')->orderBy('name')->get()->map(fn (Plan $plan) => $this->planData($plan))->values()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $plan = Plan::query()->create($this->validatedPlan($request));

        return response()->json(['data' => $this->planData($plan)], 201);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = Plan::query()->findOrFail($id);
        $plan->update($this->validatedPlan($request, true));

        return response()->json(['data' => $this->planData($plan->refresh()->loadCount('subscriptions'))]);
    }

    public function channels(Request $request): JsonResponse
    {
        $channels = Channel::query()
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->toString().'%'))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $channels->map(fn (Channel $channel) => $this->channelData($channel))->values()]);
    }

    public function updateChannel(Request $request, int $id): JsonResponse
    {
        $channel = Channel::query()->findOrFail($id);
        $channel->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json(['data' => $this->channelData($channel->refresh())]);
    }

    public function iptvPlaylists(): JsonResponse
    {
        return response()->json(['data' => [
            'playlists' => $this->iptvPlaylistsData(),
        ]]);
    }

    public function updateIptvPlaylists(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'playlists' => ['present', 'array', 'max:50'],
            'playlists.*.id' => ['nullable', 'string', 'max:100'],
            'playlists.*.name' => ['required', 'string', 'max:120'],
            'playlists.*.url' => ['required', 'url:http,https', 'max:2048'],
            'playlists.*.country' => ['nullable', 'string', 'max:10'],
            'playlists.*.language' => ['nullable', 'string', 'max:80'],
            'playlists.*.use_proxy' => ['sometimes', 'boolean'],
            'playlists.*.enabled' => ['required', 'boolean'],
            'playlists.*.priority' => ['required', 'integer', 'between:1,10000'],
        ]);

        $playlists = collect($validated['playlists'])
            ->map(fn (array $playlist, int $index): array => [
                'id' => trim((string) ($playlist['id'] ?? 'playlist-'.($index + 1))),
                'name' => trim($playlist['name']),
                'url' => trim($playlist['url']),
                'country' => $this->nullableUpper($playlist['country'] ?? null),
                'language' => $this->nullableLower($playlist['language'] ?? null),
                'use_proxy' => (bool) ($playlist['use_proxy'] ?? true),
                'enabled' => (bool) $playlist['enabled'],
                'priority' => (int) $playlist['priority'],
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        $this->settings->put('iptv.playlists', $playlists);
        foreach ($playlists as $playlist) {
            Channel::query()
                ->where('source_playlist_id', $playlist['id'])
                ->update(['use_proxy' => $playlist['use_proxy']]);
        }

        return response()->json(['data' => [
            'playlists' => $playlists,
        ]]);
    }

    public function syncIptvPlaylists(IptvOrgSyncService $sync): JsonResponse
    {
        if ($this->syncIsAsync()) {
            SyncIptvJob::dispatch();

            return response()->json(['data' => [
                'queued' => true,
                'message' => 'La sincronizacion IPTV quedo en cola y se ejecutara en segundo plano.',
            ]], 202);
        }

        try {
            return response()->json(['data' => $sync->run(
                config('pixflix.iptv.country'),
                null,
                config('pixflix.iptv.max_channels'),
            )]);
        } catch (\Throwable $exception) {
            return response()->json(['error' => [
                'code' => 'iptv_sync_failed',
                'message' => $exception->getMessage(),
            ]], 502);
        }
    }

    public function iptvVodPlaylists(): JsonResponse
    {
        return response()->json(['data' => [
            'playlists' => $this->iptvVodPlaylistsData(),
        ]]);
    }

    public function updateIptvVodPlaylists(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'playlists' => ['present', 'array', 'max:50'],
            'playlists.*.id' => ['nullable', 'string', 'max:100'],
            'playlists.*.name' => ['required', 'string', 'max:120'],
            'playlists.*.url' => ['required', 'url:http,https', 'max:2048'],
            'playlists.*.language' => ['nullable', 'string', 'max:80'],
            'playlists.*.content_type' => ['required', Rule::in(['auto', 'movie'])],
            'playlists.*.enabled' => ['required', 'boolean'],
            'playlists.*.priority' => ['required', 'integer', 'between:1,10000'],
        ]);

        $playlists = collect($validated['playlists'])
            ->map(fn (array $playlist, int $index): array => [
                'id' => trim((string) ($playlist['id'] ?? 'vod-playlist-'.($index + 1))),
                'name' => trim($playlist['name']),
                'url' => trim($playlist['url']),
                'language' => $this->nullableLower($playlist['language'] ?? null),
                'content_type' => (string) $playlist['content_type'],
                'enabled' => (bool) $playlist['enabled'],
                'priority' => (int) $playlist['priority'],
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        $this->settings->put('iptv.vod_playlists', $playlists);

        return response()->json(['data' => ['playlists' => $playlists]]);
    }

    public function syncIptvVodPlaylists(IptvVodSyncService $sync): JsonResponse
    {
        if ($this->syncIsAsync()) {
            SyncIptvVodJob::dispatch();

            return response()->json(['data' => [
                'queued' => true,
                'message' => 'La sincronizacion VOD quedo en cola y se ejecutara en segundo plano.',
            ]], 202);
        }

        try {
            return response()->json(['data' => $sync->run()]);
        } catch (\Throwable $exception) {
            return response()->json(['error' => [
                'code' => 'iptv_vod_sync_failed',
                'message' => $exception->getMessage(),
            ]], 502);
        }
    }

    public function refreshIptvResources(IptvResourceSyncService $sync): JsonResponse
    {
        if ($this->syncIsAsync()) {
            RefreshIptvResourcesJob::dispatch();

            return response()->json(['data' => [
                'queued' => true,
                'message' => 'La actualizacion de recursos quedo en cola y se ejecutara en segundo plano.',
            ]], 202);
        }

        try {
            $result = $sync->run();
        } catch (\Throwable $exception) {
            return response()->json(['error' => [
                'code' => 'iptv_resources_refresh_failed',
                'message' => $exception->getMessage(),
            ]], 502);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * When enabled, the heavy admin syncs are handed to the database queue
     * (requires QUEUE_CONNECTION=redis with Dragonfly and a deployed worker). Off by
     * default so the admin panel keeps receiving inline results.
     */
    private function syncIsAsync(): bool
    {
        return filter_var(
            config('pixflix.sync.async', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function iptvProxies(IptvProxyPool $proxyPool): JsonResponse
    {
        return response()->json(['data' => [
            'proxies' => $proxyPool->configuredForAdmin(),
        ]]);
    }

    public function updateIptvProxies(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'proxies' => ['present', 'array', 'max:20'],
            'proxies.*.id' => ['nullable', 'string', 'max:100'],
            'proxies.*.name' => ['required', 'string', 'max:120'],
            'proxies.*.base_url' => ['required', 'url:http,https', 'max:2048'],
            'proxies.*.enabled' => ['required', 'boolean'],
            'proxies.*.priority' => ['required', 'integer', 'between:1,10000'],
        ]);

        $proxies = collect($validated['proxies'])
            ->map(fn (array $proxy, int $index): array => [
                'id' => trim((string) ($proxy['id'] ?? 'proxy-'.($index + 1))),
                'name' => trim($proxy['name']),
                'base_url' => rtrim(trim($proxy['base_url']), '/'),
                'enabled' => (bool) $proxy['enabled'],
                'priority' => (int) $proxy['priority'],
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        $this->settings->put('iptv.proxies', $proxies);

        return response()->json(['data' => [
            'proxies' => $proxies,
        ]]);
    }

    public function streamFallback(): JsonResponse
    {
        return response()->json(['data' => $this->streamFallbackData()]);
    }

    public function updateStreamFallback(Request $request, StremioCatalogSyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'primary' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['required', 'integer', 'between:1,60'],
            'cache_ttl_seconds' => ['required', 'integer', 'between:60,604800'],
            'languages' => ['present', 'array', 'max:20'],
            'languages.*' => ['string', 'max:40'],
            'addons' => ['present', 'array', 'max:100'],
            'addons.*.id' => ['nullable', 'string', 'max:100'],
            'addons.*.name' => ['required', 'string', 'max:120'],
            'addons.*.base_url' => ['required', 'url:http,https', 'max:2048'],
            'addons.*.enabled' => ['required', 'boolean'],
            'addons.*.priority' => ['required', 'integer', 'between:1,10000'],
            'addons.*.timeout_seconds' => ['nullable', 'integer', 'between:1,60'],
        ]);

        $addons = collect($validated['addons'])
            ->map(fn (array $addon, int $index): array => [
                'id' => trim((string) ($addon['id'] ?? 'addon-'.($index + 1))),
                'name' => trim($addon['name']),
                'base_url' => rtrim(trim($addon['base_url']), '/'),
                'enabled' => (bool) $addon['enabled'],
                'priority' => (int) $addon['priority'],
                'timeout_seconds' => (int) ($addon['timeout_seconds'] ?? $validated['timeout_seconds']),
            ])
            ->sortBy('priority')
            ->values()
            ->all();

        $primary = (bool) ($validated['primary'] ?? $this->settings->get(
            'stremio.primary',
            config('pixflix.stremio.primary', false),
        ));

        if ($primary && ! $validated['enabled']) {
            throw ValidationException::withMessages([
                'enabled' => 'Activa Stremio antes de seleccionarlo como fuente principal.',
            ]);
        }

        if ($primary && ! collect($addons)->contains(fn (array $addon): bool => $addon['enabled'] === true)) {
            throw ValidationException::withMessages([
                'addons' => 'Agrega y activa al menos un addon Stremio para usarlo como fuente principal.',
            ]);
        }

        $this->settings->put('stremio.enabled', (bool) $validated['enabled']);
        $this->settings->put('stremio.primary', $primary);
        $this->settings->put('stremio.timeout_seconds', (int) $validated['timeout_seconds']);
        $this->settings->put('stremio.cache_ttl_seconds', (int) $validated['cache_ttl_seconds']);
        $this->settings->put('stremio.languages', array_values(array_filter(array_map('trim', $validated['languages']))));
        $this->settings->put('stremio.addons', $addons);
        $sync->invalidate();

        return response()->json(['data' => $this->streamFallbackData()]);
    }

    public function syncStreamFallbackCatalog(StremioCatalogSyncService $sync): JsonResponse
    {
        return response()->json(['data' => $sync->sync(true)]);
    }

    public function verifyStreamFallbackAddon(Request $request, StremioAddonVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:2048'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,60'],
        ]);

        return response()->json(['data' => $verifier->verify(
            $validated['base_url'],
            (int) ($validated['timeout_seconds'] ?? config('pixflix.stremio.timeout_seconds', 10)),
        )]);
    }

    public function verifyStreamFallbackContent(Request $request, StremioContentVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:2048'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,60'],
            'max_pages' => ['sometimes', 'integer', 'between:1,100'],
            'max_items' => ['sometimes', 'integer', 'between:1,5000'],
            'languages' => ['sometimes', 'array', 'max:20'],
            'languages.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => $verifier->verify(
            $validated['base_url'],
            (int) ($validated['timeout_seconds'] ?? config('pixflix.stremio.timeout_seconds', 10)),
            (int) ($validated['max_pages'] ?? 10),
            (int) ($validated['max_items'] ?? 500),
            array_key_exists('languages', $validated) ? $validated['languages'] : null,
        )]);
    }

    private function validatedPlan(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:120'],
            'price' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:999999.99'],
            'max_profiles' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'between:1,20'],
            'max_devices' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'between:1,20'],
            'max_quality' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:20'],
            'is_active' => [$isUpdate ? 'sometimes' : 'required', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    private function userData(User $user): array
    {
        $subscription = $user->subscriptions->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'username' => $user->username,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
            'subscription' => $subscription ? $this->subscriptionData($subscription) : null,
        ];
    }

    private function subscriptionData(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'is_trial' => $subscription->is_trial,
            'group_number' => $subscription->group_number,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'trial_expires_at' => $subscription->trial_expires_at?->toIso8601String(),
            'custom_price' => $subscription->custom_price,
            'user' => $subscription->relationLoaded('user') && $subscription->user ? [
                'id' => $subscription->user->id,
                'name' => $subscription->user->name,
                'email' => $subscription->user->email,
                'username' => $subscription->user->username,
            ] : null,
            'plan' => $subscription->relationLoaded('plan') && $subscription->plan ? $this->planData($subscription->plan) : null,
        ];
    }

    private function planData(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => $plan->price,
            'max_profiles' => $plan->max_profiles,
            'max_devices' => $plan->max_devices,
            'max_quality' => $plan->max_quality,
            'is_active' => $plan->is_active,
            'description' => $plan->description,
            'subscriptions_count' => $plan->subscriptions_count ?? 0,
        ];
    }

    private function channelData(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'external_id' => $channel->external_id,
            'name' => $channel->name,
            'logo' => $channel->logo,
            'category' => $channel->category,
            'country' => $channel->country,
            'language' => $channel->language,
            'is_active' => $channel->is_active,
            'has_stream' => $channel->stream_url !== null,
        ];
    }

    private function iptvPlaylistsData(): array
    {
        $playlists = $this->settings->get('iptv.playlists', null);

        if ($playlists === null) {
            return [[
                'id' => 'iptv-org-default',
                'name' => 'IPTV-org (predeterminada)',
                'url' => (string) config('pixflix.iptv.playlist_url'),
                'country' => $this->nullableUpper(config('pixflix.iptv.country')),
                'language' => null,
                'use_proxy' => true,
                'enabled' => true,
                'priority' => 1,
            ]];
        }

        return is_array($playlists)
            ? collect($playlists)
                ->filter(fn (mixed $playlist): bool => is_array($playlist) && ! empty($playlist['url']))
                ->map(fn (array $playlist): array => [
                    ...$playlist,
                    'use_proxy' => (bool) ($playlist['use_proxy'] ?? true),
                ])
                ->values()
                ->all()
            : [];
    }

    private function iptvVodPlaylistsData(): array
    {
        $playlists = $this->settings->get('iptv.vod_playlists', []);

        return is_array($playlists) ? array_values($playlists) : [];
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtoupper($value);
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtolower($value);
    }

    private function streamFallbackData(): array
    {
        $addons = $this->settings->get('stremio.addons', config('pixflix.stremio.addons', []));

        return [
            'enabled' => (bool) $this->settings->get('stremio.enabled', config('pixflix.stremio.enabled', false)),
            'primary' => (bool) $this->settings->get('stremio.primary', config('pixflix.stremio.primary', false)),
            'timeout_seconds' => (int) $this->settings->get('stremio.timeout_seconds', config('pixflix.stremio.timeout_seconds', 10)),
            'cache_ttl_seconds' => (int) $this->settings->get('stremio.cache_ttl_seconds', config('pixflix.stremio.cache_ttl_seconds', 1800)),
            'languages' => $this->settings->get('stremio.languages', config('pixflix.stremio.languages', [])),
            'addons' => is_array($addons) ? array_values($addons) : [],
        ];
    }
}
