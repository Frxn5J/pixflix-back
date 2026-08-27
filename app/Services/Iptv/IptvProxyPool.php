<?php

namespace App\Services\Iptv;

use App\Services\SyncSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class IptvProxyPool
{
    private const CURSOR_KEY = 'pixflix:iptv-proxy-cursor';

    public function __construct(private readonly SyncSettings $settings) {}

    /**
     * @return array<int, array{id: string, name: string, base_url: string, enabled: bool, priority: int}>
     */
    public function configured(): array
    {
        $proxies = $this->settings->get('iptv.proxies', config('pixflix.iptv.proxies', []));
        if (! is_array($proxies)) {
            return [];
        }

        return collect($proxies)
            ->filter(fn (mixed $proxy): bool => is_array($proxy))
            ->map(fn (array $proxy, int $index): array => [
                'id' => trim((string) ($proxy['id'] ?? 'proxy-'.($index + 1))),
                'name' => trim((string) ($proxy['name'] ?? 'Proxy '.($index + 1))),
                'base_url' => rtrim(trim((string) ($proxy['base_url'] ?? '')), '/'),
                'enabled' => (bool) ($proxy['enabled'] ?? true),
                'priority' => (int) ($proxy['priority'] ?? ($index + 1)),
            ])
            ->filter(fn (array $proxy): bool => $proxy['base_url'] !== '' && $proxy['enabled'])
            ->sortBy('priority')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string, base_url: string, enabled: bool, priority: int}>
     */
    public function configuredForAdmin(): array
    {
        $proxies = $this->settings->get('iptv.proxies', config('pixflix.iptv.proxies', []));
        if (! is_array($proxies)) {
            return [];
        }

        return collect($proxies)
            ->filter(fn (mixed $proxy): bool => is_array($proxy))
            ->map(fn (array $proxy, int $index): array => [
                'id' => trim((string) ($proxy['id'] ?? 'proxy-'.($index + 1))),
                'name' => trim((string) ($proxy['name'] ?? 'Proxy '.($index + 1))),
                'base_url' => rtrim(trim((string) ($proxy['base_url'] ?? '')), '/'),
                'enabled' => (bool) ($proxy['enabled'] ?? true),
                'priority' => (int) ($proxy['priority'] ?? ($index + 1)),
            ])
            ->filter(fn (array $proxy): bool => $proxy['base_url'] !== '')
            ->sortBy('priority')
            ->values()
            ->all();
    }

    /**
     * Tries the configured proxies in rotating order, cascading to the next
     * one whenever the previous proxy does not return a successful response.
     * With an empty pool, the stream is requested directly for compatibility.
     *
     * @param  array<string, string>  $headers
     */
    public function fetch(string $target, int $timeout, array $headers = []): ?Response
    {
        $proxies = $this->configured();
        if ($proxies === []) {
            try {
                $response = Http::timeout($timeout)
                    ->accept('*/*')
                    ->withHeaders($headers)
                    ->get($target);
            } catch (Throwable) {
                return null;
            }

            return $response->successful() ? $response : null;
        }

        $count = count($proxies);
        $start = $this->nextStart($count);

        for ($offset = 0; $offset < $count; $offset++) {
            $proxy = $proxies[($start + $offset) % $count];

            try {
                $response = Http::timeout($timeout)
                    ->accept('*/*')
                    ->withHeaders($headers)
                    ->get($this->proxyUrl($proxy['base_url'], $target));
            } catch (Throwable) {
                continue;
            }

            if ($response->successful()) {
                return $response;
            }
        }

        return null;
    }

    private function nextStart(int $count): int
    {
        $current = (int) Cache::get(self::CURSOR_KEY, -1);
        $next = ($current + 1) % $count;
        Cache::put(self::CURSOR_KEY, $next, now()->addDay());

        return $next;
    }

    private function proxyUrl(string $baseUrl, string $target): string
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $baseUrl.'?url='.rawurlencode($target);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['url'] = $target;

        $authority = $parts['scheme'].'://';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':'.$parts['pass'];
            }
            $authority .= '@';
        }
        $authority .= $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $authority.($parts['path'] ?? '/').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
