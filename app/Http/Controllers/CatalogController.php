<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\TitleDetailResource;
use App\Http\Resources\TitleResource;
use App\Models\CatalogSnapshot;
use App\Models\Title;
use App\Services\Catalog\StremioCatalogSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CatalogController extends Controller
{
    public function __construct(private readonly StremioCatalogSyncService $stremioCatalog) {}

    public function index(CatalogIndexRequest $request): JsonResponse
    {
        $this->stremioCatalog->ensureAvailable();
        $filters = $request->validated();
        $key = 'index:'.sha1((string) json_encode([$filters, $request->query('page', 1)]));
        $cached = $this->rememberCatalog($key, fn (): array => $this->indexPayload($filters));

        return $this->catalogResponse([
            'data' => $cached['data'],
            'meta' => $cached['meta'] + $this->snapshotMeta(),
            'links' => $cached['links'],
        ]);
    }

    /**
     * Warm the most common catalog responses after the first deployment.
     * Authentication and subscription middleware are intentionally bypassed:
     * this method is called only by the internal queue job.
     *
     * @return array<string, mixed>
     */
    public function warmCache(): array
    {
        $this->stremioCatalog->ensureAvailable();
        $this->rememberCatalog(
            'index:'.sha1((string) json_encode([[], 1])),
            fn (): array => $this->indexPayload([]),
        );
        $this->featured();
        $this->genres();

        return [
            'index' => true,
            'featured' => true,
            'genres' => true,
        ];
    }

    private function indexPayload(array $filters): array
    {
        $query = $this->activeTitles();

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['q'])) {
            $search = trim($filters['q']);
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (isset($filters['genre'])) {
            $query->whereJsonContains('genres', $filters['genre']);
        }

        if (isset($filters['language'])) {
            $query->whereJsonContains('languages', $filters['language']);
        }

        if (isset($filters['year'])) {
            $query->where('year', (string) $filters['year']);
        }

        if (isset($filters['quality'])) {
            $query->where('quality', $filters['quality']);
        }

        $paginator = $query
            ->orderByDesc('category')
            ->orderBy('title')
            ->paginate($filters['per_page'] ?? 20);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (Title $title) => TitleResource::make($title)->resolve())
                ->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ];
    }

    public function featured(): JsonResponse
    {
        $this->stremioCatalog->ensureAvailable();
        $data = $this->rememberCatalog('featured', function (): array {
            return $this->activeTitles()
                ->where('category', 'featured')
                ->orderBy('title')
                ->limit(12)
                ->get()
                ->map(fn (Title $title) => TitleResource::make($title)->resolve())
                ->values()->all();
        });

        return $this->catalogResponse([
            'data' => $data,
            'meta' => $this->snapshotMeta(),
        ]);
    }

    public function genres(): JsonResponse
    {
        $this->stremioCatalog->ensureAvailable();
        $genres = $this->rememberCatalog('genres', function (): array {
            return $this->activeTitles()
                ->pluck('genres')
                ->flatten()
                ->filter(fn ($genre): bool => is_string($genre) && $genre !== '')
                ->unique()
                ->sort()
                ->values()->all();
        }, 300);

        return $this->catalogResponse(['data' => $genres, 'meta' => $this->snapshotMeta()]);
    }

    public function show(string $slug): JsonResponse
    {
        $this->stremioCatalog->ensureAvailable();
        $stremioTitle = Title::query()
            ->where('source', 'stremio')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
        if ($stremioTitle !== null) {
            $this->stremioCatalog->hydrateTitle($stremioTitle);
        }

        $payload = $this->rememberCatalog('title:'.$slug, function () use ($slug): array {
            $title = $this->activeTitles()
                ->with([
                    'seasons' => fn ($query) => $query->orderBy('number'),
                    'seasons.episodes' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderBy('number'),
                ])
                ->where('slug', $slug)
                ->firstOrFail();

            return ['data' => TitleDetailResource::make($title)->resolve()];
        });

        return $this->catalogResponse([
            'data' => $payload['data'],
            'meta' => $this->snapshotMeta(),
        ]);
    }

    private function activeTitles(): Builder
    {
        return Title::query()
            ->where('source', 'stremio')
            ->where('is_active', true);
    }

    /**
     * Caches hot catalog payloads. The key embeds the snapshot version plus a
     * stamp bumped by the VOD/catalog syncs, so fresh imports invalidate the
     * cache without any explicit purging. Set PIXFLIX_CACHE_CATALOG_TTL=0 to
     * disable (used by the test-suite).
     */
    private function rememberCatalog(string $key, callable $producer, ?int $ttl = null): array
    {
        $ttl ??= (int) config('pixflix.cache.catalog_ttl', 60);

        if ($ttl <= 0) {
            return $producer();
        }

        $stamp = ($this->snapshotMeta()['snapshot_version'] ?? 'none')
            .':'.(string) Cache::get('pixflix:catalog-stamp', '0');

        return Cache::remember('pixflix:catalog:'.$key.':'.$stamp, $ttl, $producer);
    }

    private function snapshotMeta(): array
    {
        $snapshot = CatalogSnapshot::current();
        $staleAfter = max(1, (int) config('pixflix.sync.stale_after_minutes', 180));

        return [
            'snapshot_version' => $snapshot?->version,
            'snapshot_status' => $snapshot?->status,
            'snapshot_finished_at' => $snapshot?->finished_at?->toIso8601String(),
            'snapshot_stale' => $snapshot === null || $snapshot->finished_at?->lt(now()->subMinutes($staleAfter)),
        ];
    }

    private function catalogResponse(array $payload): JsonResponse
    {
        $response = response()->json($payload);
        $version = $this->snapshotMeta()['snapshot_version'];

        if ($version !== null) {
            $response->header('X-Catalog-Version', (string) $version);
        }

        return $response;
    }
}
