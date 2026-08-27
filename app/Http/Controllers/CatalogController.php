<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\TitleDetailResource;
use App\Http\Resources\TitleResource;
use App\Models\CatalogSnapshot;
use App\Models\Title;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(CatalogIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
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

        return $this->catalogResponse([
            'data' => $paginator->getCollection()
                ->map(fn (Title $title) => TitleResource::make($title)->resolve())
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ] + $this->snapshotMeta(),
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    public function featured(): JsonResponse
    {
        $titles = $this->activeTitles()
            ->where('category', 'featured')
            ->orderBy('title')
            ->limit(12)
            ->get();

        return $this->catalogResponse([
            'data' => $titles
                ->map(fn (Title $title) => TitleResource::make($title)->resolve())
                ->values(),
            'meta' => $this->snapshotMeta(),
        ]);
    }

    public function genres(): JsonResponse
    {
        $genres = $this->activeTitles()
            ->pluck('genres')
            ->flatten()
            ->filter(fn ($genre): bool => is_string($genre) && $genre !== '')
            ->unique()
            ->sort()
            ->values();

        return $this->catalogResponse(['data' => $genres, 'meta' => $this->snapshotMeta()]);
    }

    public function show(string $slug): JsonResponse
    {
        $title = $this->activeTitles()
            ->with([
                'seasons' => fn ($query) => $query->orderBy('number'),
                'seasons.episodes' => fn ($query) => $query->orderBy('number'),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->catalogResponse([
            'data' => TitleDetailResource::make($title)->resolve(),
        ]);
    }

    private function activeTitles(): Builder
    {
        $versions = $this->visibleSnapshotVersions();

        if ($versions === []) {
            return Title::query();
        }

        $latestTitleIds = Title::query()
            ->selectRaw('MAX(id)')
            ->whereIn('snapshot_version', $versions)
            ->groupBy('slug');

        return Title::query()->whereIn('id', $latestTitleIds);
    }

    private function visibleSnapshotVersions(): array
    {
        $successfulVersion = CatalogSnapshot::current()?->version;
        $versions = CatalogSnapshot::query()
            ->whereIn('status', ['partial', 'running'])
            ->when(
                $successfulVersion !== null,
                fn (Builder $query) => $query->where('version', '>', $successfulVersion),
            )
            ->pluck('version')
            ->all();

        if ($successfulVersion !== null) {
            $versions[] = $successfulVersion;
        }

        return array_values(array_unique(array_map('intval', $versions)));
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
