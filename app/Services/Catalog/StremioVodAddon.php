<?php

namespace App\Services\Catalog;

use App\Services\SyncSettings;

/**
 * The sole external VOD provider. Catalog import and stream resolution must
 * always use this exact addon; no provider chain is allowed here.
 */
class StremioVodAddon
{
    public function __construct(private readonly SyncSettings $settings) {}

    /** @return array<string, mixed>|null */
    public function configured(): ?array
    {
        $addon = $this->settings->get('stremio.vod_addon', config('pixflix.stremio.vod_addon'));

        if (! is_array($addon) || ! filter_var($addon['base_url'] ?? null, FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'id' => trim((string) ($addon['id'] ?? 'vod-addon')) ?: 'vod-addon',
            'name' => trim((string) ($addon['name'] ?? 'Addon VOD')) ?: 'Addon VOD',
            'base_url' => rtrim(trim((string) $addon['base_url']), '/'),
            'enabled' => (bool) ($addon['enabled'] ?? true),
            'timeout_seconds' => max(1, min(60, (int) ($addon['timeout_seconds'] ?? config('pixflix.stremio.timeout_seconds', 10)))),
            'priority' => 1,
        ];
    }

    /** @return array<string, mixed>|null */
    public function active(): ?array
    {
        $addon = $this->configured();

        return $addon !== null && $addon['enabled'] ? $addon : null;
    }
}
