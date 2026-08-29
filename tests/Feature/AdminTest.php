<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_read_overview_and_manage_core_records(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['name' => 'Cuenta administrada']);
        $subscription = Subscription::factory()->create(['user_id' => $target->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.users', fn (int $value) => $value >= 2);

        $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}", [
            'role' => 'agent',
        ])->assertOk()->assertJsonPath('data.role', 'agent');

        $this->withToken($token)->putJson("/api/v1/admin/subscriptions/{$subscription->id}", [
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->withToken($token)->postJson('/api/v1/admin/plans', [
            'name' => 'Panel Pro',
            'price' => 199,
            'max_profiles' => 5,
            'max_devices' => 3,
            'max_quality' => '1080p',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.name', 'Panel Pro');
    }

    public function test_subscriber_cannot_access_admin_endpoints(): void
    {
        $subscriber = User::factory()->create(['email' => 'admin-denied@test.test']);
        Subscription::factory()->create(['user_id' => $subscriber->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $subscriber->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_admin_can_manage_iptv_proxy_pool(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/admin/iptv-proxies', [
            'proxies' => [
                [
                    'id' => 'proxy-main',
                    'name' => 'Proxy principal',
                    'base_url' => 'https://proxy.example/?token=secret@123',
                    'enabled' => true,
                    'priority' => 2,
                ],
                [
                    'id' => 'proxy-backup',
                    'name' => 'Proxy respaldo',
                    'base_url' => 'https://backup.example/?token=backup',
                    'enabled' => false,
                    'priority' => 1,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.proxies.0.id', 'proxy-backup')
            ->assertJsonPath('data.proxies.1.base_url', 'https://proxy.example/?token=secret@123');

        $this->withToken($token)->getJson('/api/v1/admin/iptv-proxies')
            ->assertOk()
            ->assertJsonPath('data.proxies.0.enabled', false)
            ->assertJsonPath('data.proxies.1.name', 'Proxy principal');
    }

    public function test_admin_can_manage_iptv_playlists_and_optional_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/admin/iptv-playlists', [
            'playlists' => [
                [
                    'id' => 'sports',
                    'name' => 'Deportes MX',
                    'url' => 'https://streams.example/sports.m3u',
                    'country' => 'mx',
                    'language' => 'SPA',
                    'use_proxy' => false,
                    'enabled' => true,
                    'priority' => 2,
                ],
                [
                    'id' => 'global',
                    'name' => 'Global',
                    'url' => 'https://streams.example/global.m3u8',
                    'country' => null,
                    'language' => null,
                    'enabled' => false,
                    'priority' => 1,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.playlists.0.id', 'global')
            ->assertJsonPath('data.playlists.0.use_proxy', true)
            ->assertJsonPath('data.playlists.1.country', 'MX')
            ->assertJsonPath('data.playlists.1.language', 'spa')
            ->assertJsonPath('data.playlists.1.use_proxy', false);

        $this->withToken($token)->getJson('/api/v1/admin/iptv-playlists')
            ->assertOk()
            ->assertJsonPath('data.playlists.0.enabled', false)
            ->assertJsonPath('data.playlists.1.name', 'Deportes MX');
    }

    public function test_admin_can_sync_iptv_playlists_now(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        Http::fake([
            'https://streams.example/pluto-live-MX.m3u' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 group-title="Noticias" tvg-id="pluto-1",Canal Pluto MX
https://streams.example/pluto-1.m3u8
M3U),
        ]);

        $this->withToken($token)->putJson('/api/v1/admin/iptv-playlists', [
            'playlists' => [[
                'id' => 'pluto-mx',
                'name' => 'Pluto MX',
                'url' => 'https://streams.example/pluto-live-MX.m3u',
                'country' => null,
                'language' => null,
                'enabled' => true,
                'priority' => 1,
            ]],
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/iptv-playlists/sync')
            ->assertOk()
            ->assertJsonPath('data.channels', 1);

        $this->assertDatabaseHas('channels', [
            'external_id' => 'pluto-1',
            'country' => 'MX',
        ]);
    }

    public function test_admin_can_manage_and_sync_iptv_vod_playlists(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        Http::fake([
            'https://streams.example/vod.m3u' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="movie-1" tvg-logo="https://images.example/movie.jpg" group-title="Accion",Pelicula Uno (2025) 1080p
https://cdn.example/movie.mp4
#EXTINF:-1 tvg-id="series-1-1" group-title="Drama",Serie Demo S01E01 - Piloto
https://cdn.example/series/s01e01/master.m3u8
#EXTINF:-1 tvg-id="series-1-2" group-title="Drama",Serie Demo S01E02 - Continuacion
https://cdn.example/series/s01e02/master.m3u8
M3U),
            '*' => Http::response(['d' => []]),
        ]);

        $this->withToken($token)->putJson('/api/v1/admin/iptv-vod-playlists', [
            'playlists' => [[
                'id' => 'vod-principal',
                'name' => 'VOD principal',
                'url' => 'https://streams.example/vod.m3u',
                'language' => 'SPA',
                'content_type' => 'auto',
                'enabled' => true,
                'priority' => 1,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.playlists.0.language', 'spa')
            ->assertJsonPath('data.playlists.0.content_type', 'auto');

        $this->withToken($token)->postJson('/api/v1/admin/iptv-vod-playlists/sync')
            ->assertOk()
            ->assertJsonPath('data.titles', 2)
            ->assertJsonPath('data.movies', 1)
            ->assertJsonPath('data.series', 1)
            ->assertJsonPath('data.episodes', 2);

        $movie = Title::query()->where('title', 'Pelicula Uno')->firstOrFail();
        $series = Title::query()->where('title', 'Serie Demo')->firstOrFail();

        $this->assertSame('iptv_vod', $movie->source);
        $this->assertSame('https://cdn.example/movie.mp4', $movie->stream_url);
        $this->assertSame('tvshow', $series->type);
        $this->assertCount(2, $series->seasons()->firstOrFail()->episodes);
    }

    public function test_admin_update_refreshes_live_and_vod_iptv_resources(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->url() === 'https://streams.example/live.m3u') {
                return Http::response("#EXTM3U\n#EXTINF:-1 tvg-id=\"live-1\",Canal Uno\nhttps://streams.example/live-1.m3u8\n");
            }
            if ($request->url() === 'https://streams.example/vod.m3u') {
                return Http::response("#EXTM3U\n#EXTINF:-1 tvg-id=\"movie-1\",Pelicula Uno\nhttps://streams.example/movie.mp4\n");
            }

            return Http::response(['d' => []], 200);
        });

        $this->withToken($token)->putJson('/api/v1/admin/iptv-playlists', [
            'playlists' => [[
                'id' => 'live-principal',
                'name' => 'Canales',
                'url' => 'https://streams.example/live.m3u',
                'country' => null,
                'language' => null,
                'enabled' => true,
                'priority' => 1,
            ]],
        ])->assertOk();
        $this->withToken($token)->putJson('/api/v1/admin/iptv-vod-playlists', [
            'playlists' => [[
                'id' => 'vod-principal',
                'name' => 'Peliculas',
                'url' => 'https://streams.example/vod.m3u',
                'language' => null,
                'content_type' => 'auto',
                'enabled' => true,
                'priority' => 1,
            ]],
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/iptv-resources/refresh')
            ->assertOk()
            ->assertJsonPath('data.live.channels', 1)
            ->assertJsonPath('data.vod.movies', 1)
            ->assertJsonPath('data.errors', []);
    }
}
