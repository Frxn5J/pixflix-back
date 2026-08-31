<?php

namespace App\Http\Controllers;

use App\Services\Catalog\StremioAddonVerifier;
use App\Services\Catalog\StremioCatalogSyncService;
use App\Services\Catalog\StremioContentVerifier;
use App\Services\Iptv\IptvProxyPool;
use App\Services\Iptv\IptvResourceSyncService;
use App\Services\IptvOrg\IptvOrgSyncService;
use App\Services\IptvVod\IptvVodSyncService;
use App\Services\SyncSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class AdminWebController extends Controller
{
    public function __construct(
        private readonly AdminController $admin,
        private readonly SyncSettings $settings,
    ) {}

    public function dashboard(Request $request, IptvProxyPool $proxyPool): View
    {
        return view('admin.dashboard', [
            'section' => $this->section($request->string('section')->toString()),
            'admin' => $request->user(),
            'overview' => $this->data($this->admin->overview()),
            'users' => $this->data($this->admin->users(Request::create('/admin/users?per_page=100', 'GET', ['per_page' => 100]))),
            'subscriptions' => $this->data($this->admin->subscriptions(Request::create('/admin/subscriptions?per_page=100', 'GET', ['per_page' => 100]))),
            'plans' => $this->data($this->admin->plans()),
            'channels' => $this->data($this->admin->channels(Request::create('/admin/channels', 'GET'))),
            'iptvPlaylists' => $this->data($this->admin->iptvPlaylists())['playlists'] ?? [],
            'iptvVodPlaylists' => $this->data($this->admin->iptvVodPlaylists())['playlists'] ?? [],
            'iptvProxies' => $this->data($this->admin->iptvProxies($proxyPool))['proxies'] ?? [],
            'streamFallback' => $this->data($this->admin->streamFallback()),
        ]);
    }

    public function updateUser(Request $request, int $id): RedirectResponse
    {
        return $this->forward('users', fn () => $this->admin->updateUser($request, $id), 'Usuario actualizado.');
    }

    public function updateSubscription(Request $request, int $id): RedirectResponse
    {
        return $this->forward('subscriptions', fn () => $this->admin->updateSubscription($request, $id), 'Suscripcion actualizada.');
    }

    public function storePlan(Request $request): RedirectResponse
    {
        return $this->forward('plans', fn () => $this->admin->storePlan($request), 'Plan creado.');
    }

    public function updatePlan(Request $request, int $id): RedirectResponse
    {
        return $this->forward('plans', fn () => $this->admin->updatePlan($request, $id), 'Plan actualizado.');
    }

    public function updateChannel(Request $request, int $id): RedirectResponse
    {
        return $this->forward('channels', fn () => $this->admin->updateChannel($request, $id), 'Canal actualizado.');
    }

    public function updateIptvPlaylists(Request $request): RedirectResponse
    {
        if (! is_array($request->input('playlists'))) {
            $request->merge(['playlists' => []]);
        }

        return $this->forward('iptv-playlists', fn () => $this->admin->updateIptvPlaylists($request), 'Listas IPTV guardadas.');
    }

    public function addIptvPlaylist(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'country' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:80'],
            'use_proxy' => ['sometimes', 'boolean'],
            'priority' => ['required', 'integer', 'between:1,10000'],
        ]);
        $playlists = $this->data($this->admin->iptvPlaylists())['playlists'] ?? [];
        $playlists[] = [
            'id' => 'playlist-'.Str::lower(Str::random(10)),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'country' => $validated['country'] ?? null,
            'language' => $validated['language'] ?? null,
            'use_proxy' => (bool) ($validated['use_proxy'] ?? false),
            'enabled' => true,
            'priority' => (int) $validated['priority'],
        ];

        return $this->forward('iptv-playlists', fn () => $this->admin->updateIptvPlaylists($this->syntheticRequest([
            'playlists' => $playlists,
        ])), 'Lista IPTV agregada.');
    }

    public function removeIptvPlaylist(string $id): RedirectResponse
    {
        $playlists = collect($this->data($this->admin->iptvPlaylists())['playlists'] ?? [])
            ->reject(fn (array $playlist): bool => (string) ($playlist['id'] ?? '') === $id)
            ->values()
            ->all();

        return $this->forward('iptv-playlists', fn () => $this->admin->updateIptvPlaylists($this->syntheticRequest([
            'playlists' => $playlists,
        ])), 'Lista IPTV eliminada.');
    }

    public function syncIptvPlaylists(IptvOrgSyncService $sync): RedirectResponse
    {
        return $this->forward('iptv-playlists', fn () => $this->admin->syncIptvPlaylists($sync), 'Sincronizacion IPTV solicitada.');
    }

    public function updateIptvVodPlaylists(Request $request): RedirectResponse
    {
        if (! is_array($request->input('playlists'))) {
            $request->merge(['playlists' => []]);
        }

        return $this->forward('iptv-vod-playlists', fn () => $this->admin->updateIptvVodPlaylists($request), 'Listas VOD guardadas.');
    }

    public function addIptvVodPlaylist(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'language' => ['nullable', 'string', 'max:80'],
            'content_type' => ['required', 'in:auto,movie'],
            'use_proxy' => ['sometimes', 'boolean'],
            'priority' => ['required', 'integer', 'between:1,10000'],
        ]);
        $playlists = $this->data($this->admin->iptvVodPlaylists())['playlists'] ?? [];
        $playlists[] = [
            'id' => 'vod-playlist-'.Str::lower(Str::random(10)),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'language' => $validated['language'] ?? null,
            'content_type' => $validated['content_type'],
            'use_proxy' => (bool) ($validated['use_proxy'] ?? true),
            'enabled' => true,
            'priority' => (int) $validated['priority'],
        ];

        return $this->forward('iptv-vod-playlists', fn () => $this->admin->updateIptvVodPlaylists($this->syntheticRequest([
            'playlists' => $playlists,
        ])), 'Lista VOD agregada.');
    }

    public function removeIptvVodPlaylist(string $id): RedirectResponse
    {
        $playlists = collect($this->data($this->admin->iptvVodPlaylists())['playlists'] ?? [])
            ->reject(fn (array $playlist): bool => (string) ($playlist['id'] ?? '') === $id)
            ->values()
            ->all();

        return $this->forward('iptv-vod-playlists', fn () => $this->admin->updateIptvVodPlaylists($this->syntheticRequest([
            'playlists' => $playlists,
        ])), 'Lista VOD eliminada.');
    }

    public function syncIptvVodPlaylists(IptvVodSyncService $sync): RedirectResponse
    {
        return $this->forward('iptv-vod-playlists', fn () => $this->admin->syncIptvVodPlaylists($sync), 'Sincronizacion VOD solicitada.');
    }

    public function refreshIptvResources(IptvResourceSyncService $sync): RedirectResponse
    {
        return $this->forward('iptv-playlists', fn () => $this->admin->refreshIptvResources($sync), 'Actualizacion de recursos solicitada.');
    }

    public function updateIptvProxies(Request $request): RedirectResponse
    {
        if (! is_array($request->input('proxies'))) {
            $request->merge(['proxies' => []]);
        }

        return $this->forward('iptv-proxies', fn () => $this->admin->updateIptvProxies($request), 'Proxies guardados.');
    }

    public function addIptvProxy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url:http,https', 'max:2048'],
            'priority' => ['required', 'integer', 'between:1,10000'],
        ]);
        $proxies = $this->data($this->admin->iptvProxies(app(IptvProxyPool::class)))['proxies'] ?? [];
        $proxies[] = [
            'id' => 'proxy-'.Str::lower(Str::random(10)),
            'name' => $validated['name'],
            'base_url' => $validated['base_url'],
            'enabled' => true,
            'priority' => (int) $validated['priority'],
        ];

        return $this->forward('iptv-proxies', fn () => $this->admin->updateIptvProxies($this->syntheticRequest([
            'proxies' => $proxies,
        ])), 'Proxy agregado.');
    }

    public function removeIptvProxy(string $id): RedirectResponse
    {
        $proxies = collect($this->data($this->admin->iptvProxies(app(IptvProxyPool::class)))['proxies'] ?? [])
            ->reject(fn (array $proxy): bool => (string) ($proxy['id'] ?? '') === $id)
            ->values()
            ->all();

        return $this->forward('iptv-proxies', fn () => $this->admin->updateIptvProxies($this->syntheticRequest([
            'proxies' => $proxies,
        ])), 'Proxy eliminado.');
    }

    public function updateStreamFallback(Request $request, StremioCatalogSyncService $sync): RedirectResponse
    {
        $languages = collect(explode(',', (string) $request->input('languages_csv', '')))
            ->map(fn (string $language): string => trim($language))
            ->filter()
            ->values()
            ->all();
        $request->merge([
            'languages' => $languages,
            'addons' => is_array($request->input('addons')) ? $request->input('addons') : [],
        ]);

        return $this->forward('fallback', fn () => $this->admin->updateStreamFallback($request, $sync), 'Configuracion de Stremio guardada.');
    }

    public function addStremioAddon(Request $request, StremioAddonVerifier $verifier, StremioCatalogSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'url:http,https', 'max:2048'],
            'priority' => ['required', 'integer', 'between:1,10000'],
            'timeout_seconds' => ['required', 'integer', 'between:1,60'],
        ]);
        $verification = $verifier->verify($validated['base_url'], (int) $validated['timeout_seconds']);

        if (! ($verification['compatible'] ?? false)) {
            return $this->redirectTo('fallback')
                ->withInput()
                ->with('verification', $verification)
                ->withErrors(['base_url' => 'El addon no es compatible con recursos de catalogo y stream.']);
        }

        $current = $this->data($this->admin->streamFallback());
        $current['addons'][] = [
            'id' => 'addon-'.Str::lower(Str::random(10)),
            'name' => $validated['name'],
            'base_url' => rtrim($validated['base_url'], '/'),
            'enabled' => true,
            'priority' => (int) $validated['priority'],
            'timeout_seconds' => (int) $validated['timeout_seconds'],
        ];
        $current['languages'] = is_array($current['languages'] ?? null) ? $current['languages'] : [];

        try {
            $this->assertSuccessful($this->admin->updateStreamFallback($this->syntheticRequest($current), $sync));
        } catch (Throwable $exception) {
            Log::warning('Admin web could not install Stremio addon', ['exception' => $exception]);

            return $this->redirectTo('fallback')->withInput()->with('verification', $verification)->withErrors(['admin' => $exception->getMessage()]);
        }

        return $this->redirectTo('fallback')->with('verification', $verification)->with('success', 'Addon verificado e instalado.');
    }

    public function removeStremioAddon(string $id, StremioCatalogSyncService $sync): RedirectResponse
    {
        $current = $this->data($this->admin->streamFallback());
        $current['addons'] = collect($current['addons'] ?? [])
            ->reject(fn (array $addon): bool => (string) ($addon['id'] ?? '') === $id)
            ->values()
            ->all();
        $current['languages'] = is_array($current['languages'] ?? null) ? $current['languages'] : [];

        return $this->forward('fallback', fn () => $this->admin->updateStreamFallback($this->syntheticRequest($current), $sync), 'Addon eliminado.');
    }

    public function verifyStremioContent(Request $request, StremioContentVerifier $verifier): RedirectResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:2048'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,60'],
            'languages_csv' => ['nullable', 'string', 'max:500'],
        ]);
        $languages = collect(explode(',', (string) ($validated['languages_csv'] ?? '')))
            ->map(fn (string $language): string => trim($language))
            ->filter()
            ->values()
            ->all();
        $verification = $verifier->verify(
            $validated['base_url'],
            (int) ($validated['timeout_seconds'] ?? config('pixflix.stremio.timeout_seconds', 10)),
            (int) config('pixflix.stremio.catalog_max_pages', 10),
            (int) config('pixflix.stremio.catalog_max_items', 500),
            $languages === [] ? null : $languages,
        );

        return $this->redirectTo('fallback')->with('content_verification', $verification);
    }

    public function syncStreamFallbackCatalog(StremioCatalogSyncService $sync): RedirectResponse
    {
        return $this->forward('fallback', fn () => $this->admin->syncStreamFallbackCatalog($sync), 'Catalogo Stremio importado.');
    }

    public function createTrial(Request $request): RedirectResponse
    {
        $response = app(TrialController::class)->store($request);

        return $this->redirectTo('trials')->with('trial_credentials', $this->data($response))->with('success', 'Cuenta de prueba creada.');
    }

    private function section(string $section): string
    {
        return in_array($section, [
            'overview', 'users', 'subscriptions', 'plans', 'channels',
            'iptv-playlists', 'iptv-vod-playlists', 'iptv-proxies', 'fallback', 'trials',
        ], true) ? $section : 'overview';
    }

    private function forward(string $section, callable $operation, string $message): RedirectResponse
    {
        try {
            $this->assertSuccessful($operation());
        } catch (Throwable $exception) {
            Log::warning('Admin web action failed', [
                'section' => $section,
                'exception' => $exception,
            ]);

            return $this->redirectTo($section)->withInput()->withErrors(['admin' => $exception->getMessage()]);
        }

        return $this->redirectTo($section)->with('success', $message);
    }

    private function redirectTo(string $section): RedirectResponse
    {
        return redirect()->route('admin.dashboard', ['section' => $section]);
    }

    private function syntheticRequest(array $payload): Request
    {
        return Request::create('/admin', 'POST', $payload);
    }

    private function data(JsonResponse $response): array
    {
        $this->assertSuccessful($response);
        $payload = $response->getData(true);

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    private function assertSuccessful(JsonResponse $response): void
    {
        if ($response->getStatusCode() >= 400) {
            $payload = $response->getData(true);
            throw new \RuntimeException((string) ($payload['error']['message'] ?? 'No fue posible completar la operacion.'));
        }
    }
}
