<?php

namespace Tests\Feature;

use App\Models\CatalogSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use App\Services\Catalog\CatalogNormalizer;
use App\Services\Catalog\CatalogSyncService;
use App\Services\Catalog\PrincipalCatalogClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('pixflix.catalog.primary_url', 'https://primary.test');
        config()->set('pixflix.catalog.fallback_url', null);
        config()->set('pixflix.catalog.retry_attempts', 1);
        config()->set('pixflix.catalog.retry_delays_ms', [0]);
        config()->set('pixflix.catalog.circuit_threshold', 3);
    }

    public function test_sync_creates_versioned_snapshot_and_populates_catalog(): void
    {
        Http::fake(fn (Request $request) => $this->providerResponse($request));

        $result = app(CatalogSyncService::class)->run();

        $this->assertSame('success', $result['status']);
        $this->assertDatabaseHas('catalog_snapshots', [
            'version' => 1,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('titles', [
            'slug' => 'aurora-cero',
            'snapshot_version' => 1,
        ]);

        $this->assertSame(1, CatalogSnapshot::current()?->version);
    }

    public function test_partial_sync_can_resume_and_previous_snapshot_remains_visible(): void
    {
        CatalogSnapshot::factory()->create(['version' => 1, 'status' => 'success']);
        Title::factory()->create(['slug' => 'catalogo-anterior', 'snapshot_version' => 1]);

        $failed = true;
        Http::fake(function (Request $request) use (&$failed) {
            if (str_contains($request->url(), '/extract')) {
                if ($failed) {
                    return Http::response(['error' => 'rate limited'], 429);
                }

                return Http::response($this->detailPayload(), 200);
            }

            return Http::response($this->listResponse($request), 200);
        });

        $first = app(CatalogSyncService::class)->run();
        $this->assertSame('partial', $first['status']);
        $this->assertSame(1, CatalogSnapshot::current()?->version);

        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');
        $this->withToken($token)
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertHeader('X-Catalog-Version', '1')
            ->assertJsonPath('data.0.slug', 'catalogo-anterior')
            ->assertJsonPath('meta.snapshot_version', 1);

        $failed = false;
        $second = app(CatalogSyncService::class)->run();

        $this->assertSame('success', $second['status']);
        $this->assertSame(2, CatalogSnapshot::current()?->version);
        $this->assertDatabaseHas('titles', ['slug' => 'aurora-cero', 'snapshot_version' => 2]);
    }

    public function test_catalog_lock_prevents_a_second_sync(): void
    {
        $lock = Cache::lock('pixflix:sync:catalog', 3600);
        $this->assertTrue($lock->get());

        try {
            $result = app(CatalogSyncService::class)->run();
            $this->assertSame('locked', $result['status']);
        } finally {
            $lock->release();
        }
    }

    public function test_catalog_normalizer_discards_local_image_urls(): void
    {
        $normalized = app(CatalogNormalizer::class)->titleItem([
            'title' => 'Imagen local',
            'image' => 'file:///C:/poster.jpg',
        ]);

        $this->assertNull($normalized['poster']);
    }

    public function test_client_uses_fallback_when_primary_is_unavailable(): void
    {
        config()->set('pixflix.catalog.fallback_url', 'https://fallback.test');
        Http::fake([
            'https://primary.test/*' => Http::response([], 503),
            'https://fallback.test/*' => Http::response(['items' => []], 200),
        ]);

        $payload = app(PrincipalCatalogClient::class)->list('movies', 1);

        $this->assertSame([], $payload['items']);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://primary.test/'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://fallback.test/'));
    }

    public function test_client_unwraps_provider_extract_url_before_requesting_extract(): void
    {
        Http::fake(fn () => Http::response(['title' => 'Aurora Cero'], 200));

        app(PrincipalCatalogClient::class)->extract(
            'https://primary.test//extract?url=https://zonaaps.com/movies/aurora-cero/',
        );

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['url'] ?? null) === 'https://zonaaps.com/movies/aurora-cero/';
        });
    }

    public function test_episode_extract_failure_does_not_abort_the_catalog_snapshot(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/extract')) {
                if (str_contains($request->url(), 'episode')) {
                    return Http::response(['error' => 'upstream unavailable'], 503);
                }

                return Http::response($this->seriesDetailPayload(), 200);
            }

            if (str_contains($request->url(), 'type=movies')) {
                return Http::response(['items' => []], 200);
            }

            return Http::response([
                'items' => [[
                    'post_id' => 'show-1',
                    'title' => 'Serie Aurora',
                    'url' => 'https://zonaaps.com/tvshows/serie-aurora/',
                    'extractUrl' => 'https://zonaapis.com/extract?url=serie-aurora',
                    'contentType' => 'tvshow',
                ]],
            ], 200);
        });

        $result = app(CatalogSyncService::class)->run();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['stats']['errors']);
        $this->assertDatabaseHas('titles', ['slug' => 'serie-aurora']);
        $this->assertDatabaseHas('episodes', [
            'title' => 'Episodio piloto',
            'streams' => null,
        ]);
    }

    public function test_sync_command_streams_debug_logs(): void
    {
        Http::fake(fn (Request $request) => $this->providerResponse($request));

        $this->artisan('pixflix:sync-catalog', ['--debug' => true])
            ->expectsOutputToContain('Catalog sync started')
            ->expectsOutputToContain('Catalog sync page received')
            ->assertExitCode(0);
    }

    private function providerResponse(Request $request): mixed
    {
        if (str_contains($request->url(), '/extract')) {
            return Http::response($this->detailPayload(), 200);
        }

        return Http::response($this->listResponse($request), 200);
    }

    private function listResponse(Request $request): array
    {
        if (str_contains($request->url(), 'type=movies')) {
            return [
                'items' => [[
                    'post_id' => 'movie-1',
                    'title' => 'Aurora Cero',
                    'url' => 'https://zonaapis.com/movies/aurora-cero/',
                    'extractUrl' => 'https://zonaapis.com/extract?url=aurora-cero',
                    'contentType' => 'movie',
                ]],
            ];
        }

        return ['items' => []];
    }

    private function detailPayload(): array
    {
        return [
            'url' => 'https://zonaapis.com/movies/aurora-cero/',
            'title' => 'Aurora Cero',
            'contentType' => 'movie',
            'description' => 'Contenido sincronizado.',
            'poster' => 'https://cdn.test/aurora.jpg',
            'streams' => [],
        ];
    }

    private function seriesDetailPayload(): array
    {
        return [
            'url' => 'https://zonaaps.com/tvshows/serie-aurora/',
            'title' => 'Serie Aurora',
            'contentType' => 'tvshow',
            'totalSeasons' => 1,
            'totalEpisodes' => 1,
            'seasons' => [[
                'season' => 1,
                'title' => 'Temporada 1',
                'episodes' => [[
                    'episode' => 1,
                    'title' => 'Episodio piloto',
                    'url' => 'https://zonaaps.com/episodes/serie-aurora-1x1/',
                    'extractUrl' => 'https://zonaapis.com/extract?url=episode-1',
                ]],
            ]],
        ];
    }
}
