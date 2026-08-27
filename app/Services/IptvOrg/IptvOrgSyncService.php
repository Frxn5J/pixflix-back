<?php

namespace App\Services\IptvOrg;

use App\Models\Channel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IptvOrgSyncService
{
    public function __construct(private readonly IptvOrgClient $client) {}

    /**
     * @return array{channels: int, streams: int, deactivated: int}
     */
    public function run(?string $country = null, ?int $limit = null): array
    {
        $country = $country !== null && trim($country) !== '' ? strtoupper(trim($country)) : null;
        $limit = $limit !== null && $limit > 0 ? $limit : null;
        $rows = [];
        $seen = [];

        foreach ($this->client->entries() as $entry) {
            $id = $entry['external_id'];
            if (isset($seen[$id]) || ($country !== null && $entry['country'] !== $country)) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = [
                'external_id' => $id,
                'name' => $entry['name'],
                'logo' => $entry['logo'],
                'category' => $entry['category'],
                'country' => $entry['country'],
                'language' => $entry['language'],
                'stream_url' => $entry['stream_url'],
                // upsert bypasses Eloquent casts, so persist the JSON explicitly.
                'stream_headers' => json_encode($entry['stream_headers'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ];

            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            throw new RuntimeException('iptv-org no devolvio canales reproducibles para el filtro indicado.');
        }

        $shouldDeactivate = $limit === null;

        return DB::transaction(function () use ($rows, $shouldDeactivate, $country): array {
            foreach (array_chunk($rows, 500) as $chunk) {
                Channel::query()->upsert(
                    $chunk,
                    ['external_id'],
                    ['name', 'logo', 'category', 'country', 'language', 'stream_url', 'stream_headers', 'is_active', 'updated_at'],
                );
            }

            $deactivated = 0;
            if ($shouldDeactivate) {
                $activeIds = array_column($rows, 'external_id');
                $deactivated = Channel::query()
                    ->whereNotNull('external_id')
                    ->whereNotIn('external_id', $activeIds)
                    ->when($country !== null, fn ($query) => $query->where('country', $country))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }

            return [
                'channels' => count($rows),
                'streams' => count($rows),
                'deactivated' => $deactivated,
            ];
        });
    }
}
