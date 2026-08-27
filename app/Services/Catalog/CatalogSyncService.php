<?php

namespace App\Services\Catalog;

use App\Jobs\SyncCatalogEpisodeJob;
use App\Jobs\SyncCatalogPageJob;
use App\Jobs\SyncCatalogTitleJob;
use App\Models\CatalogSnapshot;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CatalogSyncService
{
    public function __construct(
        private readonly PrincipalCatalogClient $client,
        private readonly CatalogNormalizer $normalizer,
    ) {}

    public function normalizePage(string $type, int $page): array
    {
        $payload = $this->client->list($type, $page);

        return array_map(
            fn (array $item): array => $this->normalizer->titleItem($item),
            $payload['items'] ?? [],
        );
    }

    public function normalizeDetail(string $url): array
    {
        return $this->normalizer->titleDetail($this->client->extract($url));
    }

    public function run(string $triggeredBy = 'manual', bool $force = false, bool $restart = false): array
    {
        $lock = Cache::lock('pixflix:sync:catalog', max(1, (int) config('pixflix.sync.lock_seconds', 3600)));

        if (! $lock->get()) {
            return ['status' => 'locked', 'message' => 'Ya existe una sincronizacion en curso.'];
        }

        $snapshot = $this->resumableSnapshot($triggeredBy, $force, $restart);
        Log::info('Catalog sync started', [
            'snapshot_version' => $snapshot->version,
            'snapshot_id' => $snapshot->id,
            'restart' => $restart,
        ]);

        try {
            $checkpoint = $snapshot->checkpoint ?: $this->initialCheckpoint();
            $types = $checkpoint['types'] ?? ['movies', 'tvshows'];

            while (($typeIndex = (int) ($checkpoint['type_index'] ?? 0)) < count($types)) {
                $type = (string) $types[$typeIndex];
                $page = max(1, (int) ($checkpoint['page'] ?? 1));
                dispatch_sync(new SyncCatalogPageJob($snapshot->id, $type, $page));
                $result = $snapshot->fresh()->checkpoint['last_page_result'] ?? ['next_page' => null];

                if ($result['next_page'] === null) {
                    $checkpoint = [
                        'types' => $types,
                        'type_index' => $typeIndex + 1,
                        'page' => 1,
                    ];
                } else {
                    $checkpoint = [
                        'types' => $types,
                        'type_index' => $typeIndex,
                        'page' => $result['next_page'],
                    ];
                }

                $snapshot->update(['checkpoint' => $checkpoint]);
            }

            $snapshot->update([
                'status' => 'success',
                'finished_at' => now(),
                'checkpoint' => null,
                'error' => null,
            ]);
            Log::info('Catalog sync completed', $this->logContext($snapshot));

            return [
                'status' => 'success',
                'snapshot_version' => $snapshot->version,
                'stats' => $snapshot->fresh()->stats ?? [],
            ];
        } catch (Throwable $error) {
            $this->incrementStat($snapshot, 'errors');
            $snapshot->update([
                'status' => 'partial',
                'finished_at' => now(),
                'error' => $error->getMessage(),
            ]);
            Log::warning('Catalog sync paused', $this->logContext($snapshot) + ['error' => $error->getMessage()]);

            return [
                'status' => 'partial',
                'snapshot_version' => $snapshot->version,
                'error' => $error->getMessage(),
                'stats' => $snapshot->fresh()->stats ?? [],
            ];
        } finally {
            $lock->release();
        }
    }

    public function syncPage(CatalogSnapshot $snapshot, string $type, int $page): array
    {
        $maxPages = max(1, (int) config('pixflix.catalog.max_pages', 1000));

        if ($page > $maxPages) {
            throw new \RuntimeException("Se alcanzo el maximo de paginas configurado ({$maxPages}) para {$type}.");
        }

        $payload = $this->client->list($type, $page);
        $items = array_values(array_filter($payload['items'] ?? [], 'is_array'));
        Log::info('Catalog sync page received', [
            'type' => $type,
            'page' => $page,
            'items' => count($items),
            'total_pages' => $payload['totalPages'] ?? $payload['total_pages'] ?? null,
        ]);

        foreach ($items as $item) {
            Log::debug('Catalog sync title started', [
                'type' => $type,
                'title' => $item['title'] ?? null,
                'url' => $this->safeUrl($item['url'] ?? $item['extractUrl'] ?? null),
            ]);
            dispatch_sync(new SyncCatalogTitleJob($snapshot->id, $item));
        }

        $this->incrementStat($snapshot, 'pages');
        $nextPage = $this->nextPage($payload, $page, count($items));

        if ($nextPage !== null && $page >= $maxPages) {
            throw new \RuntimeException("Se alcanzo el maximo de paginas configurado ({$maxPages}) para {$type}.");
        }

        $snapshot->update([
            'checkpoint' => array_merge($snapshot->fresh()->checkpoint ?? [], [
                'last_page_result' => ['next_page' => $nextPage, 'items' => count($items)],
            ]),
        ]);

        return ['next_page' => $nextPage, 'items' => count($items)];
    }

    public function syncTitle(CatalogSnapshot $snapshot, array $item): void
    {
        $normalizedItem = $this->normalizer->titleItem($item);
        $url = (string) ($item['extractUrl'] ?? $item['extract_url'] ?? $item['url'] ?? '');

        if ($url === '') {
            throw new \RuntimeException('El item no tiene URL de extract.');
        }

        $rawDetail = $this->client->extract($url);
        $detail = $this->normalizer->titleDetail($rawDetail);

        if (trim((string) ($detail['title'] ?? '')) === '') {
            throw new \RuntimeException('El extract no devolvio titulo para '.$this->safeUrl($url));
        }

        $attributes = array_replace($normalizedItem, $detail, [
            'external_id' => $normalizedItem['external_id'] ?? $detail['external_id'] ?? null,
            'snapshot_version' => $snapshot->version,
            'raw_extract' => $rawDetail,
        ]);

        $title = DB::transaction(function () use ($attributes, $detail, $snapshot) {
            $title = \App\Models\Title::query()
                ->where('slug', $attributes['slug'])
                ->when(
                    $attributes['external_id'] !== null,
                    fn ($query) => $query->orWhere('external_id', $attributes['external_id']),
                )
                ->first();

            if ($title === null) {
                $title = \App\Models\Title::query()->create($attributes);
            } else {
                $title->update($attributes);
            }

            foreach ($detail['seasons'] ?? [] as $seasonData) {
                $season = Season::query()->updateOrCreate(
                    ['title_id' => $title->id, 'number' => (int) ($seasonData['number'] ?? 0)],
                    [
                        'title' => $seasonData['title'] ?? 'Temporada',
                        'release_date' => $seasonData['release_date'] ?? null,
                    ],
                );

                foreach ($seasonData['episodes'] ?? [] as $episode) {
                    dispatch_sync(new SyncCatalogEpisodeJob($snapshot->id, $season->id, $episode));
                }
            }

            return $title;
        });

        $this->incrementStat($snapshot, $title->type === 'tvshow' ? 'tvshows' : 'movies');
        Log::debug('Catalog sync title saved', [
            'title_id' => $title->id,
            'title' => $title->title,
            'slug' => $title->slug,
        ]);
    }

    public function syncEpisode(CatalogSnapshot $snapshot, int $seasonId, array $episode): void
    {
        $url = (string) ($episode['extract_url'] ?? $episode['extractUrl'] ?? $episode['url'] ?? '');
        $streams = null;

        if ($url !== '') {
            try {
                $raw = $this->client->extract($url);
                $streams = $raw['streams'] ?? null;
            } catch (Throwable $error) {
                $this->incrementStat($snapshot, 'errors');
                Log::warning('Catalog episode extract skipped', [
                    'snapshot_version' => $snapshot->version,
                    'season_id' => $seasonId,
                    'episode' => $episode['number'] ?? null,
                    'url' => $this->safeUrl($url),
                    'error' => $error->getMessage(),
                ]);
            }
        }

        Episode::query()->updateOrCreate(
            ['season_id' => $seasonId, 'number' => (int) ($episode['number'] ?? 0)],
            [
                'title' => $episode['title'] ?? 'Episodio',
                'url' => $episode['url'] ?? null,
                'image' => $episode['image'] ?? null,
                'release_date' => $episode['release_date'] ?? null,
                'extract_url' => $episode['extract_url'] ?? null,
                'streams' => $streams,
            ],
        );
        $this->incrementStat($snapshot, 'episodes');
    }

    private function resumableSnapshot(string $triggeredBy, bool $force, bool $restart): CatalogSnapshot
    {
        $snapshot = CatalogSnapshot::query()
            ->whereIn('status', ['partial', 'running'])
            ->latest('id')
            ->first();

        if ($restart && $snapshot !== null) {
            $snapshot->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Sincronizacion reiniciada manualmente.',
            ]);
            $snapshot = null;
        }

        if ($snapshot !== null && ($snapshot->status === 'partial' || $snapshot->started_at?->lt(now()->subMinutes((int) config('pixflix.sync.stale_after_minutes', 180))) || $force)) {
            $snapshot->update(['status' => 'running', 'finished_at' => null, 'error' => null]);

            return $snapshot;
        }

        $version = ((int) CatalogSnapshot::query()->max('version')) + 1;

        return CatalogSnapshot::query()->create([
            'version' => $version,
            'started_at' => now(),
            'status' => 'running',
            'stats' => ['movies' => 0, 'tvshows' => 0, 'episodes' => 0, 'pages' => 0],
            'checkpoint' => $this->initialCheckpoint(),
            'triggered_by' => $triggeredBy,
        ]);
    }

    private function initialCheckpoint(): array
    {
        return ['types' => ['movies', 'tvshows'], 'type_index' => 0, 'page' => 1];
    }

    private function nextPage(array $payload, int $page, int $itemCount): ?int
    {
        $pagination = $payload['pagination'] ?? $payload['meta'] ?? [];
        $totalPages = $pagination['totalPages']
            ?? $pagination['total_pages']
            ?? $pagination['last_page']
            ?? $payload['totalPages']
            ?? $payload['total_pages']
            ?? $payload['last_page']
            ?? null;

        if (is_numeric($totalPages)) {
            return $page < (int) $totalPages ? $page + 1 : null;
        }

        if (($payload['hasNextPage'] ?? $payload['has_next_page'] ?? false) === true) {
            return $page + 1;
        }

        return $itemCount >= max(1, (int) config('pixflix.catalog.page_size', 50))
            ? $page + 1
            : null;
    }

    private function incrementStat(CatalogSnapshot $snapshot, string $key): void
    {
        $snapshot->refresh();
        $stats = $snapshot->stats ?? [];
        $stats[$key] = ((int) ($stats[$key] ?? 0)) + 1;
        $snapshot->update(['stats' => $stats]);
    }

    private function logContext(CatalogSnapshot $snapshot): array
    {
        return [
            'snapshot_version' => $snapshot->version,
            'status' => $snapshot->status,
            'stats' => $snapshot->stats,
        ];
    }

    private function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts)
            ? (($parts['host'] ?? '').($parts['path'] ?? ''))
            : '[invalid-url]';
    }
}
