<?php

namespace App\Services\Iptv;

use App\Services\SyncSettings;

class IptvProxyPool
{
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
     * Configuration exposed to the authenticated frontend. The browser
     * applies these URLs when a stream requires a proxy, so the backend does
     * not download and relay media bytes.
     *
     * @return array{required: bool, proxies: array<int, array{id: string, name: string, base_url: string, priority: int}>}
     */
    public function playbackConfig(bool $required): array
    {
        return [
            'required' => $required,
            'proxies' => collect($this->configured())
                ->map(fn (array $proxy): array => [
                    'id' => $proxy['id'],
                    'name' => $proxy['name'],
                    'base_url' => $proxy['base_url'],
                    'priority' => $proxy['priority'],
                ])
                ->values()
                ->all(),
        ];
    }

}
