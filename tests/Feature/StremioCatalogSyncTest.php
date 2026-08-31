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

    public function test_admin_can_force_stremio_catalog_import(): void
    {
        $this->fakeCatalogAddon();
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
            ->assertJsonPath('data.series', 1);
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
