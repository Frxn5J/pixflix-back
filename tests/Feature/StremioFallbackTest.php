<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\User;
use App\Services\Catalog\StreamResolver;
use App\Services\SyncSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StremioFallbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('pixflix.catalog.primary_url', 'https://api.test');
        config()->set('pixflix.catalog.fallback_url', null);
        config()->set('pixflix.catalog.retry_attempts', 1);
        config()->set('pixflix.catalog.retry_delays_ms', [0]);
        app(SyncSettings::class)->put('stremio.enabled', true);
        app(SyncSettings::class)->put('stremio.languages', ['Latino']);
        app(SyncSettings::class)->put('stremio.timeout_seconds', 2);
        app(SyncSettings::class)->put('stremio.cache_ttl_seconds', 1800);
    }

    public function test_resolution_uses_cache_before_api_and_addons(): void
    {
        $title = Title::factory()->create([
            'raw_extract' => [
                'url' => 'https://catalog.test/movie/cache-first',
                'streams' => [[
                    'hls' => 'https://cdn.test/cache-first.m3u8',
                    'language' => 'Latino',
                ]],
            ],
        ]);
        $this->configureAddons();
        Http::fake();

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/cache-first.m3u8', $streams[0]['hls']);
        Http::assertNothingSent();
    }

    public function test_resolution_uses_api_before_stremio(): void
    {
        $title = Title::factory()->create([
            'raw_extract' => ['url' => 'https://catalog.test/movie/api-first', 'streams' => []],
        ]);
        $this->configureAddons();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.test/extract')) {
                return Http::response(['streams' => [[
                    'hls' => 'https://cdn.test/api-first.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/api-first.m3u8', $streams[0]['hls']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'addon-two.test'));
    }

    public function test_primary_stremio_resolution_skips_legacy_cache_and_api(): void
    {
        $title = Title::factory()->create([
            'raw_extract' => [
                'url' => 'https://catalog.test/movie/legacy-source',
                'streams' => [[
                    'hls' => 'https://cdn.test/legacy.m3u8',
                    'language' => 'Latino',
                ]],
            ],
        ]);
        $this->configureAddons();
        app(SyncSettings::class)->put('stremio.primary', true);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'addon-one.test')) {
                return Http::response(['streams' => [[
                    'url' => 'https://cdn.test/stremio-primary.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/stremio-primary.m3u8', $streams[0]['hls']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.test/extract'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'catalog.test'));
    }

    public function test_addons_are_tried_in_priority_order_and_dead_torrents_are_filtered(): void
    {
        $title = Title::factory()->create([
            'raw_extract' => ['url' => null, 'streams' => []],
        ]);
        $this->configureAddons();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'addon-one.test')) {
                return Http::response(['streams' => [
                    [
                        'name' => 'Torrent Latino 0 seeders 0 leechers',
                        'url' => 'magnet:?xt=urn:btih:dead',
                        'seeders' => 0,
                        'leechers' => 0,
                    ],
                    [
                        'name' => 'Torrent Latino sin estado',
                        'infoHash' => 'unknown-peer-state',
                        'url' => 'magnet:?xt=urn:btih:unknown',
                    ],
                    [
                        'name' => 'English stream',
                        'url' => 'https://cdn.test/english.m3u8',
                        'language' => 'English',
                    ],
                ]], 200);
            }

            return Http::response(['streams' => [[
                'name' => 'Latino live 1080p',
                'url' => 'https://cdn.test/working.m3u8',
                'language' => 'Latino',
                'quality' => '1080p',
            ]]], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertCount(1, $streams);
        $this->assertSame('https://cdn.test/working.m3u8', $streams[0]['hls']);
        $urls = collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all();
        $this->assertStringContainsString('addon-one.test', $urls[0]);
        $this->assertStringContainsString('addon-two.test', $urls[1]);
    }

    public function test_resolver_uses_the_title_imdb_id_when_raw_extract_has_none(): void
    {
        $title = Title::factory()->create([
            'title' => 'Shrek 2',
            'imdb_id' => 'tt0298148',
            'raw_extract' => ['streams' => []],
        ]);
        $this->configureAddons();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/stream/movie/tt0298148.json')) {
                return Http::response(['streams' => [[
                    'url' => 'https://cdn.test/shrek-2.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/shrek-2.m3u8', $streams[0]['hls']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/stream/movie/tt0298148.json'));
    }

    public function test_search_enabled_addon_resolves_titles_without_external_ids(): void
    {
        $title = Title::factory()->create([
            'title' => 'Shrek 2',
            'raw_extract' => ['streams' => []],
        ]);
        $this->configureAddons();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'addon-one.test') && str_ends_with($url, '/manifest.json')) {
                return Http::response([
                    'catalogs' => [[
                        'type' => 'movie',
                        'id' => 'search-movie',
                        'extra' => [['name' => 'search', 'isRequired' => true]],
                    ]],
                ], 200);
            }
            if (str_contains($url, '/catalog/movie/search-movie/search=Shrek%202.json')) {
                return Http::response(['metas' => [[
                    'id' => 'tt0298148',
                    'name' => 'Shrek 2',
                ]]], 200);
            }
            if (str_contains($url, '/stream/movie/tt0298148.json')) {
                return Http::response(['streams' => [[
                    'url' => 'https://cdn.test/shrek-2.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/shrek-2.m3u8', $streams[0]['hls']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/catalog/movie/search-movie/search=Shrek%202.json'));
    }

    public function test_addon_manifest_path_prefix_is_preserved_for_stream_requests(): void
    {
        $title = Title::factory()->create([
            'title' => 'Shrek 2',
            'imdb_id' => 'tt0298148',
            'raw_extract' => ['streams' => []],
        ]);
        app(SyncSettings::class)->put('stremio.addons', [[
            'id' => 'prefixed-addon',
            'name' => 'Addon con prefijo',
            'base_url' => 'https://addon-prefix.test/secret/manifest.json',
            'enabled' => true,
            'priority' => 1,
            'timeout_seconds' => 2,
        ]]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/secret/stream/movie/tt0298148.json')) {
                return Http::response(['streams' => [[
                    'url' => 'https://cdn.test/shrek-2.m3u8',
                    'language' => 'Latino',
                ]]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $streams = app(StreamResolver::class)->titleStreams($title);

        $this->assertSame('https://cdn.test/shrek-2.m3u8', $streams[0]['hls']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'https://addon-prefix.test/secret/stream/movie/tt0298148.json'));
    }

    public function test_admin_can_save_ordered_stremio_fallback_configuration(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $response = $this->withToken($token)->putJson('/api/v1/admin/stream-fallback', [
            'enabled' => true,
            'timeout_seconds' => 8,
            'cache_ttl_seconds' => 3600,
            'languages' => ['Latino', 'English'],
            'addons' => [
                [
                    'id' => 'second',
                    'name' => 'Addon dos',
                    'base_url' => 'https://addon-two.test/manifest.json',
                    'enabled' => true,
                    'priority' => 20,
                    'timeout_seconds' => 7,
                ],
                [
                    'id' => 'first',
                    'name' => 'Addon uno',
                    'base_url' => 'https://addon-one.test',
                    'enabled' => false,
                    'priority' => 10,
                    'timeout_seconds' => 5,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.primary', false)
            ->assertJsonPath('data.addons.0.id', 'first')
            ->assertJsonPath('data.addons.1.id', 'second')
            ->assertJsonPath('data.addons.0.base_url', 'https://addon-one.test');

        $this->assertDatabaseHas('settings', ['key' => 'stremio.addons']);
    }

    public function test_admin_can_enable_stremio_as_the_primary_stream_source(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/admin/stream-fallback', [
            'enabled' => true,
            'primary' => true,
            'timeout_seconds' => 8,
            'cache_ttl_seconds' => 3600,
            'languages' => ['Latino'],
            'addons' => [[
                'id' => 'primary-addon',
                'name' => 'Addon principal',
                'base_url' => 'https://addon-primary.test/manifest.json',
                'enabled' => true,
                'priority' => 1,
                'timeout_seconds' => 8,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.primary', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.addons.0.base_url', 'https://addon-primary.test/manifest.json');

        $this->assertDatabaseHas('settings', ['key' => 'stremio.primary', 'value' => 'true']);
    }

    public function test_admin_can_verify_a_manifest_without_persisting_the_result(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');
        Http::fake([
            'https://addon-verify.test/manifest.json' => Http::response([
                'id' => 'org.example.addon',
                'name' => 'Addon Latino',
                'version' => '1.0.0',
                'resources' => ['catalog', 'stream'],
                'types' => ['movie', 'series'],
                'catalogs' => [
                    ['type' => 'movie', 'id' => 'latino', 'name' => 'Películas Español Latino'],
                ],
            ], 200),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/stream-fallback/verify', [
                'base_url' => 'https://addon-verify.test/manifest.json',
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.compatible', true)
            ->assertJsonPath('data.spanish_signal', true)
            ->assertJsonPath('data.catalogs', 1);

        $this->assertDatabaseMissing('settings', ['key' => 'stremio.addons']);
    }

    public function test_admin_can_deep_verify_language_and_torrent_availability_without_caching(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_ends_with($url, '/manifest.json')) {
                return Http::response([
                    'id' => 'org.deep.addon',
                    'name' => 'Addon profundo',
                    'version' => '1.0.0',
                    'resources' => ['catalog', 'stream'],
                    'types' => ['movie', 'series', 'channel'],
                    'catalogs' => [
                        ['type' => 'movie', 'id' => 'latino', 'name' => 'Latino'],
                        ['type' => 'series', 'id' => 'series', 'name' => 'Series'],
                        ['type' => 'channel', 'id' => 'live', 'name' => 'Vivos'],
                    ],
                ], 200);
            }
            if (str_contains($url, '/catalog/movie/latino.json')) {
                return Http::response(['metas' => [
                    ['id' => 'movie-latino', 'name' => 'Película Latino', 'language' => 'es-419'],
                    ['id' => 'movie-english', 'name' => 'Película English', 'language' => 'English'],
                ]], 200);
            }
            if (str_contains($url, '/catalog/series/series.json')) {
                return Http::response(['metas' => [
                    ['id' => 'series-latino', 'name' => 'Serie Español', 'languages' => ['Español']],
                ]], 200);
            }
            if (str_contains($url, '/catalog/channel/live.json')) {
                return Http::response(['metas' => [
                    ['id' => 'channel-latino', 'name' => 'Canal Latino'],
                ]], 200);
            }
            if (str_contains($url, '/stream/movie/movie-latino.json')) {
                return Http::response(['streams' => [
                    ['name' => 'Torrent muerto Latino', 'url' => 'magnet:?xt=urn:btih:dead', 'seeders' => 0, 'leechers' => 0],
                    ['name' => 'Torrent activo Latino', 'url' => 'magnet:?xt=urn:btih:alive', 'seeders' => 4, 'leechers' => 2],
                ]], 200);
            }
            if (str_contains($url, '/stream/series/series-latino.json')) {
                return Http::response(['streams' => [
                    ['name' => 'Serie Latino', 'url' => 'https://cdn.test/series.m3u8', 'language' => 'Latino'],
                ]], 200);
            }
            if (str_contains($url, '/stream/channel/channel-latino.json')) {
                return Http::response(['streams' => [
                    ['name' => 'Vivo Latino', 'url' => 'https://cdn.test/live.m3u8', 'language' => 'Latino'],
                ]], 200);
            }
            if (str_contains($url, '/stream/movie/movie-english.json')) {
                return Http::response(['streams' => [
                    ['name' => 'English', 'url' => 'https://cdn.test/english.m3u8', 'language' => 'English'],
                ]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $this->withToken($token)
            ->postJson('/api/v1/admin/stream-fallback/verify-content', [
                'base_url' => 'https://addon-deep.test',
                'max_pages' => 2,
                'max_items' => 20,
                'languages' => ['Latino'],
            ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.totals.movies', 2)
            ->assertJsonPath('data.totals.series', 1)
            ->assertJsonPath('data.totals.live', 1)
            ->assertJsonPath('data.totals.spanish_latino_movies', 1)
            ->assertJsonPath('data.totals.spanish_latino_series', 1)
            ->assertJsonPath('data.totals.spanish_latino_live', 1)
            ->assertJsonPath('data.streams.healthy_torrents', 1)
            ->assertJsonPath('data.streams.dead_torrents', 1)
            ->assertJsonPath('data.streams.playable', 2)
            ->assertJsonPath('data.streams.language_rejected', 1);

        $this->assertDatabaseMissing('settings', ['key' => 'stremio.addons']);
    }

    public function test_deep_verification_uses_stremio_catalog_pagination_limits(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');
        $firstPage = array_map(fn (int $number): array => [
            'id' => 'movie-'.$number,
            'name' => 'Película '.$number,
            'language' => 'Latino',
        ], range(1, 100));
        Http::fake(function (Request $request) use ($firstPage) {
            $url = $request->url();
            if (str_ends_with($url, '/manifest.json')) {
                return Http::response([
                    'id' => 'org.pagination.addon',
                    'name' => 'Addon paginado',
                    'version' => '1.0.0',
                    'resources' => ['catalog', 'stream'],
                    'types' => ['movie'],
                    'catalogs' => [['type' => 'movie', 'id' => 'all']],
                ], 200);
            }
            if (str_ends_with($url, '/catalog/movie/all.json')) {
                return Http::response(['metas' => $firstPage], 200);
            }
            if (str_ends_with($url, '/catalog/movie/all/skip=100.json')) {
                return Http::response(['metas' => [['id' => 'movie-101', 'name' => 'Película 101', 'language' => 'Latino']]], 200);
            }

            return Http::response(['streams' => []], 200);
        });

        $response = $this->withToken($token)->postJson('/api/v1/admin/stream-fallback/verify-content', [
            'base_url' => 'https://addon-pagination.test',
            'max_pages' => 2,
            'max_items' => 101,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.catalogs.0.pages', 2)
            ->assertJsonPath('data.catalogs.0.items', 101)
            ->assertJsonPath('data.totals.movies', 101)
            ->assertJsonPath('data.totals.spanish_latino_movies', 101);
    }

    private function configureAddons(): void
    {
        app(SyncSettings::class)->put('stremio.addons', [
            [
                'id' => 'one',
                'name' => 'Addon uno',
                'base_url' => 'https://addon-one.test',
                'enabled' => true,
                'priority' => 10,
                'timeout_seconds' => 2,
            ],
            [
                'id' => 'two',
                'name' => 'Addon dos',
                'base_url' => 'https://addon-two.test',
                'enabled' => true,
                'priority' => 20,
                'timeout_seconds' => 2,
            ],
        ]);
    }
}
