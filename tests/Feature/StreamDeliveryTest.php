<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use App\Services\SyncSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_iptv_vod_playback_metadata_does_not_fetch_media_on_the_backend(): void
    {
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-front-proxy',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'source_playlist_id' => 'vod-front',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.m3u8',
        ]);
        app(SyncSettings::class)->put('iptv.proxies', [[
            'id' => 'proxy-front',
            'name' => 'Proxy para frontend',
            'base_url' => 'https://proxy.example/fetch?token=secret',
            'enabled' => true,
            'priority' => 1,
        ]]);
        app(SyncSettings::class)->put('iptv.vod_playlists', [[
            'id' => 'vod-front',
            'name' => 'VOD frontend',
            'url' => 'https://streams.example/vod.m3u',
            'content_type' => 'auto',
            'use_proxy' => true,
            'enabled' => true,
            'priority' => 1,
        ]]);
        Http::fake();

        $response = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-front-proxy/streams')
            ->assertOk()
            ->assertJsonPath('data.0.hls', 'https://cdn.example/movies/pelicula.m3u8')
            ->assertJsonPath('data.0.proxy.required', true)
            ->assertJsonPath('data.0.proxy.proxies.0.id', 'proxy-front');

        $this->assertStringNotContainsString('/api/v1/vod/', (string) $response->json('data.0.hls'));
        Http::assertNothingSent();
    }

    public function test_iptv_channel_playback_metadata_can_bypass_the_proxy(): void
    {
        [, $token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal directo',
            'stream_url' => 'https://cdn.example/live/direct.m3u8',
            'use_proxy' => false,
            'is_active' => true,
        ]);
        Http::fake();

        $response = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk()
            ->assertJsonPath('data.stream.hls', 'https://cdn.example/live/direct.m3u8')
            ->assertJsonPath('data.stream.proxy.required', false);

        Http::assertNothingSent();
        $this->assertStringNotContainsString('/api/v1/channels/', (string) $response->json('data.stream.hls'));
    }

    public function test_catalog_index_is_cached_until_the_stamp_changes(): void
    {
        config(['pixflix.cache.catalog_ttl' => 60]);
        [, $token] = $this->subscriber();
        Title::factory()->create(['title' => 'Cacheable Uno', 'is_active' => true]);

        $this->withToken($token)->getJson('/api/v1/catalog?q=Cacheable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        Title::factory()->create(['title' => 'Cacheable Dos', 'is_active' => true]);
        $this->withToken($token)->getJson('/api/v1/catalog?q=Cacheable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        Cache::forever('pixflix:catalog-stamp', 'changed');
        $this->withToken($token)->getJson('/api/v1/catalog?q=Cacheable')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_channels_index_cache_invalidates_on_channel_changes(): void
    {
        config(['pixflix.cache.channels_ttl' => 60]);
        [, $token] = $this->subscriber();
        Channel::query()->create(['name' => 'Canal Uno', 'is_active' => true]);

        $this->withToken($token)->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        Channel::query()->create(['name' => 'Canal Dos', 'is_active' => true]);
        $this->withToken($token)->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    private function subscriber(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return [$user, $token];
    }
}
