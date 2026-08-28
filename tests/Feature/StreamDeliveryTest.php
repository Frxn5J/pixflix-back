<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_xaccel_delivery_redirects_direct_video_without_fetching_upstream(): void
    {
        config(['pixflix.streaming.delivery' => 'xaccel']);
        [, $token] = $this->subscriber();
        $movie = Title::factory()->create([
            'slug' => 'pelicula-accel',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.mp4?token=source',
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-accel/streams')
            ->assertOk()
            ->json('data.0.mp4');

        Http::fake(['*' => Http::response('must-not-be-called', 500)]);

        $response = $this->get($this->pathAndQuery($streamUrl))
            ->assertOk()
            ->assertHeader('X-Accel-Redirect');

        $location = (string) $response->headers->get('X-Accel-Redirect');
        $this->assertStringStartsWith('/internal/upstream?target=', $location);
        $this->assertStringContainsString(rawurlencode('https://cdn.example/movies/pelicula.mp4?token=source'), $location);
        Http::assertNothingSent();
        $this->assertNotNull($movie);
    }

    public function test_xaccel_delivery_still_rewrites_manifests_with_php(): void
    {
        config(['pixflix.streaming.delivery' => 'xaccel']);
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-accel-hls',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/master.m3u8',
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-accel-hls/streams')
            ->assertOk()
            ->json('data.0.hls');

        Http::fake([
            'https://cdn.example/*' => Http::response("#EXTM3U\n#EXTINF:6,\nsegment-001.ts\n", 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]),
        ]);

        $response = $this->get($this->pathAndQuery($streamUrl))->assertOk();
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
        $this->assertStringContainsString('/api/v1/vod/title/', $response->getContent());
    }

    public function test_xaccel_channel_stream_redirects_non_manifest_targets(): void
    {
        config(['pixflix.streaming.delivery' => 'xaccel']);
        [, $token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal TS',
            'stream_url' => 'https://cdn.test/live/sin-extension',
            'stream_headers' => ['Referer' => 'https://ref.example/'],
            'is_active' => true,
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk()
            ->json('data.stream.hls');

        Http::fake(['*' => Http::response('must-not-be-called', 500)]);

        $response = $this->get($this->pathAndQuery($streamUrl))
            ->assertOk()
            ->assertHeader('X-Accel-Redirect');

        $location = (string) $response->headers->get('X-Accel-Redirect');
        $this->assertStringContainsString(rawurlencode('https://cdn.test/live/sin-extension'), $location);
        $this->assertStringContainsString('referer='.rawurlencode('https://ref.example/'), $location);
        Http::assertNothingSent();
    }

    public function test_xaccel_redirect_routes_through_the_proxy_rotation(): void
    {
        config(['pixflix.streaming.delivery' => 'xaccel']);
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-accel-proxy',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/proxy.mp4',
        ]);
        app(\App\Services\SyncSettings::class)->put('iptv.proxies', [[
            'id' => 'proxy-one',
            'name' => 'Proxy uno',
            'base_url' => 'https://proxy-one.test/?token=one',
            'enabled' => true,
            'priority' => 1,
        ]]);
        Cache::forget('pixflix:iptv-proxy-cursor');

        $streamUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-accel-proxy/streams')
            ->assertOk()
            ->json('data.0.mp4');

        Http::fake(['*' => Http::response('must-not-be-called', 500)]);

        $response = $this->get($this->pathAndQuery($streamUrl))->assertOk();
        $location = urldecode((string) $response->headers->get('X-Accel-Redirect'));
        $this->assertStringContainsString('proxy-one.test', $location);
        $this->assertStringContainsString('token=one', $location);
        Http::assertNothingSent();
    }

    public function test_php_delivery_remains_the_default(): void
    {
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-php',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.mp4',
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-php/streams')
            ->assertOk()
            ->json('data.0.mp4');

        Http::fake([
            'https://cdn.example/*' => Http::response('video-bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        $response = $this->get($this->pathAndQuery($streamUrl))->assertOk();
        $this->assertFalse($response->headers->has('X-Accel-Redirect'));
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        Http::assertSentCount(1);
    }

    public function test_catalog_index_is_cached_until_the_stamp_changes(): void
    {
        config(['pixflix.cache.catalog_ttl' => 60]);
        [, $token] = $this->subscriber();
        Title::factory()->create(['title' => 'Cacheable Uno', 'is_active' => true]);

        $this->withToken($token)->getJson('/api/v1/catalog?q=Cacheable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // Without a stamp change the cached payload is served...
        Title::factory()->create(['title' => 'Cacheable Dos', 'is_active' => true]);
        $this->withToken($token)->getJson('/api/v1/catalog?q=Cacheable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        // ...and bumping the stamp (as the syncs do) invalidates it.
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

    public function test_external_proxy_serves_direct_video_and_channel_ts(): void
    {
        config([
            'pixflix.streaming.proxy_base_url' => 'https://pixflix-stream.example.workers.dev',
            'pixflix.streaming.proxy_secret' => 'worker-secret',
        ]);
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-externa',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.mp4?token=source',
        ]);
        $channel = Channel::query()->create([
            'name' => 'Canal TS externo',
            'stream_url' => 'https://cdn.test/live/stream.ts',
            'is_active' => true,
        ]);

        $vodUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-externa/streams')
            ->assertOk()
            ->assertJsonPath('data.0.hls', null)
            ->json('data.0.mp4');

        parse_str((string) parse_url($vodUrl, PHP_URL_QUERY), $vodQuery);
        $this->assertStringStartsWith('https://pixflix-stream.example.workers.dev/?', $vodUrl);
        $this->assertSame('https://cdn.example/movies/pelicula.mp4?token=source', $vodQuery['target']);
        $this->assertSame(
            hash_hmac('sha256', 'stream|'.$vodQuery['expires'].'|'.$vodQuery['target'], 'worker-secret'),
            $vodQuery['signature'],
        );

        $channelUrl = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk()
            ->json('data.stream.hls');

        parse_str((string) parse_url($channelUrl, PHP_URL_QUERY), $channelQuery);
        $this->assertStringStartsWith('https://pixflix-stream.example.workers.dev/?', $channelUrl);
        $this->assertSame('https://cdn.test/live/stream.ts', $channelQuery['target']);
    }

    public function test_external_proxy_keeps_manifests_on_the_backend_and_routes_segments_out(): void
    {
        config([
            'pixflix.streaming.proxy_base_url' => 'https://pixflix-stream.example.workers.dev',
            'pixflix.streaming.proxy_secret' => 'worker-secret',
        ]);
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-externa-hls',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/master.m3u8',
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-externa-hls/streams')
            ->assertOk()
            ->json('data.0.hls');

        // The top-level manifest still belongs to the backend (it must be rewritten).
        $this->assertStringContainsString('/api/v1/vod/title/', $streamUrl);
        $this->assertStringNotContainsString('workers.dev', $streamUrl);

        Http::fake([
            'https://cdn.example/movies/master.m3u8' => Http::response(
                "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n720p/playlist.m3u8\n",
                200,
                ['Content-Type' => 'application/vnd.apple.mpegurl'],
            ),
            'https://cdn.example/movies/720p/playlist.m3u8' => Http::response(
                "#EXTM3U\n#EXTINF:6,\nsegment-001.ts\n",
                200,
                ['Content-Type' => 'application/vnd.apple.mpegurl'],
            ),
        ]);

        // Variant playlist entry stays on the backend...
        $master = $this->get($this->pathAndQuery($streamUrl))->assertOk();
        $variantUrl = collect(preg_split('/\r?\n/', $master->getContent()))
            ->first(fn (string $line): bool => str_starts_with($line, 'http'));
        $this->assertIsString($variantUrl);
        $this->assertStringContainsString('/api/v1/vod/title/', $variantUrl);

        // ...while the segment entry goes to the Worker with a valid signature.
        $variant = $this->get($this->pathAndQuery($variantUrl))->assertOk();
        $segmentUrl = collect(preg_split('/\r?\n/', $variant->getContent()))
            ->first(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'));
        $this->assertIsString($segmentUrl);
        parse_str((string) parse_url($segmentUrl, PHP_URL_QUERY), $segmentQuery);
        $this->assertStringStartsWith('https://pixflix-stream.example.workers.dev/?', $segmentUrl);
        $this->assertSame('https://cdn.example/movies/720p/segment-001.ts', $segmentQuery['target']);
        $this->assertSame(
            hash_hmac('sha256', 'stream|'.$segmentQuery['expires'].'|'.$segmentQuery['target'], 'worker-secret'),
            $segmentQuery['signature'],
        );
    }

    public function test_external_proxy_forwards_provider_headers(): void
    {
        config([
            'pixflix.streaming.proxy_base_url' => 'https://pixflix-stream.example.workers.dev',
            'pixflix.streaming.proxy_secret' => 'worker-secret',
        ]);
        [, $token] = $this->subscriber();
        Title::factory()->create([
            'slug' => 'pelicula-externa-headers',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.mp4',
            'stream_headers' => ['Referer' => 'https://provider.example/'],
        ]);

        $vodUrl = $this->withToken($token)
            ->getJson('/api/v1/titles/pelicula-externa-headers/streams')
            ->assertOk()
            ->json('data.0.mp4');

        $this->assertStringContainsString(
            'referer='.rawurlencode('https://provider.example/'),
            $vodUrl,
        );
    }

    private function pathAndQuery(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH).'?'.(string) parse_url($url, PHP_URL_QUERY);
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
