<?php

namespace Tests\Feature;

use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Catalog\StreamResolver;
use App\Services\SyncSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StremioCatalogSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pixflix.stremio.catalog_max_pages', 2);
        config()->set('pixflix.stremio.catalog_max_items', 500);
        config()->set('pixflix.stremio.catalog_sync_ttl_seconds', 900);
        app(SyncSettings::class)->put('stremio.enabled', true);
        app(SyncSettings::class)->put('stremio.primary', true);
        app(SyncSettings::class)->put('stremio.addons', [[
            'id' => 'catalog-addon',
            'name' => 'Addon catálogo',
            'base_url' => 'https://addon-catalog.test/manifest.json?token=test',
            'enabled' => true,
            'priority' => 1,
            'timeout_seconds' => 2,
        ]]);
        Cache::forget('pixflix:stremio:catalog:last-sync');
    }

    public function test_catalog_request_imports_stremio_titles_and_makes_them_visible(): void
    {
        $this->fakeCatalogAddon();
        $token = $this->subscriberToken();

        $response = $this->withToken($token)->getJson('/api/v1/catalog?q=Stremio');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.title', 'Película Stremio')
            ->assertJsonPath('data.1.title', 'Serie Stremio');
        $this->assertDatabaseHas('titles', [
            'source' => 'stremio',
            'external_id' => 'stremio:movie:movie-1',
            'imdb_id' => null,
        ]);
        $this->assertDatabaseHas('titles', [
            'source' => 'stremio',
            'external_id' => 'stremio:series:series-1',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://addon-catalog.test/manifest.json?token=test');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://addon-catalog.test/catalog/movie/library.json?token=test');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://addon-catalog.test/catalog/series/library.json?token=test');
    }

    public function test_series_detail_hydrates_stremio_episodes_on_demand(): void
    {
        $title = Title::factory()->create([
            'source' => 'stremio',
            'external_id' => 'stremio:series:series-1',
            'slug' => 'serie-stremio',
            'type' => 'tvshow',
            'title' => 'Serie Stremio',
            'raw_extract' => [
                'source' => 'stremio',
                'stremio_id' => 'series-1',
                'stremio_type' => 'series',
                'addon_id' => 'catalog-addon',
            ],
        ]);
        $token = $this->subscriberToken();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/meta/series/series-1.json')) {
                return Http::response(['meta' => [
                    'description' => 'Serie importada',
                    'videos' => [[
                        'id' => 'series-1:1:1',
                        'season' => 1,
                        'episode' => 1,
                        'title' => 'El comienzo',
                        'released' => '2025-01-10T00:00:00.000Z',
                    ]],
                ]], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->withToken($token)->getJson('/api/v1/titles/'.$title->slug);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Serie Stremio')
            ->assertJsonPath('data.seasons.0.number', 1)
            ->assertJsonPath('data.seasons.0.episodes.0.number', 1)
            ->assertJsonPath('data.seasons.0.episodes.0.title', 'El comienzo');
        $this->assertDatabaseHas('episodes', [
            'source' => 'stremio',
            'number' => 1,
            'title' => 'El comienzo',
        ]);
        $this->assertTrue(Season::query()->where('title_id', $title->id)->exists());
    }

    public function test_series_detail_falls_back_to_tmdb_when_addon_has_no_meta(): void
    {
        config()->set('pixflix.tmdb.api_key', '');
        config()->set('pixflix.tmdb.access_token', 'tmdb-token');
        config()->set('pixflix.tmdb.base_url', 'https://tmdb.test/3');

        $title = Title::factory()->create([
            'source' => 'stremio',
            'external_id' => 'stremio:series:tt37532893',
            'slug' => 'black-torch',
            'type' => 'tvshow',
            'title' => 'BLACK TORCH',
            'imdb_id' => 'tt37532893',
            'raw_extract' => [
                'source' => 'stremio',
                'stremio_id' => 'tt37532893',
                'stremio_type' => 'series',
                'addon_id' => 'catalog-addon',
            ],
        ]);
        $token = $this->subscriberToken();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/meta/series/tt37532893.json')) {
                return Http::response(['meta' => null], 200);
            }
            if (str_contains($url, '/find/tt37532893')) {
                return Http::response(['tv_results' => [['id' => 999]]], 200);
            }
            if (str_contains($url, '/tv/999/season/1')) {
                return Http::response(['episodes' => [
                    [
                        'episode_number' => 1,
                        'name' => 'El comienzo',
                        'air_date' => '2025-01-10',
                        'still_path' => '/still.jpg',
                    ],
                    [
                        'episode_number' => 2,
                        'name' => 'La misión',
                        'air_date' => '2025-01-17',
                        'still_path' => null,
                    ],
                ]], 200);
            }
            if (str_contains($url, '/tv/999')) {
                return Http::response([
                    'id' => 999,
                    'name' => 'BLACK TORCH',
                    'overview' => 'Serie TMDB',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'first_air_date' => '2025-01-01',
                    'vote_average' => 8.2,
                    'genres' => [['name' => 'Acción']],
                    'external_ids' => ['imdb_id' => 'tt37532893'],
                    'seasons' => [['season_number' => 1]],
                    'episode_run_time' => [24],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->withToken($token)->getJson('/api/v1/titles/'.$title->slug);

        $response->assertOk()
            ->assertJsonPath('data.total_seasons', 1)
            ->assertJsonPath('data.total_episodes', 2)
            ->assertJsonPath('data.seasons.0.number', 1)
            ->assertJsonPath('data.seasons.0.episodes.1.title', 'La misión');
        $this->assertDatabaseHas('episodes', [
            'source' => 'stremio',
            'number' => 1,
            'title' => 'El comienzo',
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/find/tt37532893'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tv/999/season/1'));
    }

    public function test_admin_can_force_stremio_catalog_import(): void
    {
        $this->fakeCatalogAddon();
        app(SyncSettings::class)->put('stremio.primary', false);
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/stream-fallback/sync-catalog');

        $response->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.titles', 2)
            ->assertJsonPath('data.movies', 1)
            ->assertJsonPath('data.series', 1)
            ->assertJsonPath('data.addon_counts.0.movies', 1)
            ->assertJsonPath('data.addon_counts.0.series', 1)
            ->assertJsonPath('data.addon_counts.0.titles', 2);
    }

    public function test_imported_stremio_id_and_addon_query_are_used_for_playback(): void
    {
        $title = Title::factory()->create([
            'source' => 'stremio',
            'external_id' => 'stremio:movie:custom-1',
            'raw_extract' => [
                'source' => 'stremio',
                'stremio_id' => 'custom-1',
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/stream/')) {
                return Http::response(['streams' => [[
                    'url' => 'https://cdn.test/custom-1.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 404);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/custom-1.m3u8', $streams[0]['hls']);
        $urls = collect(Http::recorded())
            ->map(fn (array $record): string => $record[0]->url())
            ->all();
        $this->assertContains('https://addon-catalog.test/stream/movie/custom-1.json?token=test', $urls);
    }

    public function test_catalog_sync_does_not_import_stream_addon_catalogs(): void
    {
        app(SyncSettings::class)->put('stremio.catalog_enabled', true);
        app(SyncSettings::class)->put('stremio.catalog_addons', [[
            'id' => 'catalog-only',
            'name' => 'Catálogo principal',
            'base_url' => 'https://catalog-only.test',
            'enabled' => true,
            'priority' => 1,
            'timeout_seconds' => 2,
        ]]);
        app(SyncSettings::class)->put('stremio.stream_addons', [[
            'id' => 'stream-only',
            'name' => 'Reproducción externa',
            'base_url' => 'https://stream-only.test',
            'enabled' => true,
            'priority' => 1,
            'timeout_seconds' => 2,
        ]]);
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'catalog-only.test') && str_ends_with($url, '/manifest.json')) {
                return Http::response([
                    'id' => 'catalog-only',
                    'name' => 'Catálogo principal',
                    'version' => '1.0.0',
                    'resources' => ['catalog'],
                    'types' => ['movie'],
                    'catalogs' => [['type' => 'movie', 'id' => 'library']],
                ], 200);
            }
            if (str_contains($url, 'catalog-only.test/catalog/movie/library.json')) {
                return Http::response(['metas' => [['id' => 'movie-1', 'name' => 'Película principal']]], 200);
            }

            return Http::response([], 404);
        });

        $result = app(\App\Services\Catalog\StremioCatalogSyncService::class)->sync(true);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['titles']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'stream-only.test'));
    }

    public function test_empty_catalog_addon_does_not_deactivate_existing_titles(): void
    {
        $title = Title::factory()->create([
            'source' => 'stremio',
            'is_active' => true,
        ]);
        app(SyncSettings::class)->put('stremio.catalog_enabled', true);
        app(SyncSettings::class)->put('stremio.catalog_addons', [[
            'id' => 'empty-catalog',
            'name' => 'Catálogo vacío',
            'base_url' => 'https://empty-catalog.test',
            'enabled' => true,
            'priority' => 1,
            'timeout_seconds' => 2,
        ]]);
        Http::fake([
            'https://empty-catalog.test/manifest.json' => Http::response([
                'id' => 'empty-catalog',
                'name' => 'Catálogo vacío',
                'version' => '1.0.0',
                'resources' => ['catalog'],
                'types' => ['movie'],
                'catalogs' => [['type' => 'movie', 'id' => 'library']],
            ], 200),
            'https://empty-catalog.test/catalog/movie/library.json' => Http::response(['metas' => []], 200),
        ]);

        $result = app(\App\Services\Catalog\StremioCatalogSyncService::class)->sync(true);

        $this->assertSame('partial', $result['status']);
        $this->assertTrue((bool) $title->fresh()->is_active);
    }

    private function fakeCatalogAddon(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'addon-catalog.test') && ! str_contains($url, '/catalog/')) {
                return Http::response([
                    'id' => 'org.catalog.addon',
                    'name' => 'Addon catálogo',
                    'version' => '1.0.0',
                    'resources' => ['catalog', 'stream'],
                    'types' => ['movie', 'series'],
                    'catalogs' => [
                        ['type' => 'movie', 'id' => 'library', 'name' => 'Películas'],
                        ['type' => 'series', 'id' => 'library', 'name' => 'Series'],
                    ],
                ], 200);
            }
            if (str_contains($url, '/catalog/movie/library.json')) {
                return Http::response(['metas' => [[
                    'id' => 'movie-1',
                    'name' => 'Película Stremio',
                    'description' => 'Una película importada',
                    'genres' => ['Acción'],
                    'year' => 2025,
                ]]], 200);
            }
            if (str_contains($url, '/catalog/series/library.json')) {
                return Http::response(['metas' => [[
                    'id' => 'series-1',
                    'name' => 'Serie Stremio',
                    'genres' => ['Drama'],
                ]]], 200);
            }

            return Http::response([], 404);
        });
    }

    private function subscriberToken(): string
    {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan_id' => null,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        return $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');
    }
}
