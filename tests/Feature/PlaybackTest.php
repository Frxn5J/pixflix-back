<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Season;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use App\Services\SyncSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaybackTest extends TestCase
{
    use DatabaseTransactions;

    public function test_movie_streams_and_episode_streams_use_normalized_contract(): void
    {
        [$user, $token, $profile] = $this->subscriberWithProfile();
        $movie = Title::factory()->create(['type' => 'movie', 'slug' => 'aurora-cero', 'raw_extract' => ['streams' => [['source' => 'principal', 'quality' => '1080p', 'language' => 'Latino', 'hls' => 'https://cdn.test/hls/aurora.m3u8', 'mp4' => 'https://cdn.test/mp4/aurora.mp4']]]]);
        $title = Title::factory()->create(['type' => 'tvshow', 'slug' => 'reacher']);
        $season = Season::factory()->create(['title_id' => $title->id, 'number' => 1]);
        $episode = $season->episodes()->create(['number' => 1, 'title' => 'Piloto', 'url' => 'https://zonaaps.com/episodes/reacher-1x1/', 'extract_url' => 'https://zonaaps.com/episodes/reacher-1x1/', 'streams' => [['source' => 'principal', 'hls' => 'https://cdn.test/hls/ep.m3u8', 'mp4' => 'https://cdn.test/mp4/ep.mp4', 'quality' => '720p']]]);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/titles/aurora-cero/streams')
            ->assertOk()->assertJsonPath('data.0.hls', 'https://cdn.test/hls/aurora.m3u8')
            ->assertJsonPath('data.0.mp4', 'https://cdn.test/mp4/aurora.mp4')
            ->assertJsonMissingPath('data.0.extractUrl')
            ->assertJsonMissingPath('data.0.source')
            ->assertJsonMissingPath('data.0.headers');

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson("/api/v1/episodes/{$episode->id}/streams")
            ->assertOk()->assertJsonPath('data.0.hls', 'https://cdn.test/hls/ep.m3u8');

        $this->assertDatabaseHas('playback_logs', ['title_id' => $movie->id]);
        $this->assertDatabaseHas('playback_logs', ['episode_id' => $episode->id]);
    }

    public function test_resolve_and_progress_are_isolated_by_profile(): void
    {
        [$user, $token, $profile] = $this->subscriberWithProfile();
        $movie = Title::factory()->create(['type' => 'movie', 'slug' => 'horizonte-rojo']);
        $title = Title::factory()->create(['type' => 'tvshow', 'slug' => 'planeta-azul']);
        $season = Season::factory()->create(['title_id' => $title->id, 'number' => 1]);
        $episode = $season->episodes()->create(['number' => 1, 'title' => 'E1', 'url' => 'https://zonaaps.com/episodes/e1/', 'extract_url' => 'https://zonaaps.com/episodes/e1/']);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/v1/catalog/resolve', ['slug' => 'horizonte-rojo'])
            ->assertOk()->assertJsonStructure(['data']);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/v1/catalog/resolve', ['episode_id' => $episode->id])
            ->assertOk()->assertJsonStructure(['data']);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/v1/catalog/resolve', [])
            ->assertStatus(422);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/v1/catalog/resolve', ['slug' => 'no-existe'])
            ->assertNotFound();

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/v1/progress', ['title_id' => $movie->id, 'position_sec' => 120, 'duration_sec' => 3600])
            ->assertOk()->assertJsonPath('data.position_sec', 120)->assertJsonPath('data.title_id', $movie->id);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/v1/progress', ['episode_id' => $episode->id, 'position_sec' => 600, 'duration_sec' => 1800])
            ->assertOk()->assertJsonPath('data.episode_id', $episode->id);

        $this->actingAs($user, 'sanctum')->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/progress/continue-watching')
            ->assertOk()->assertJsonCount(2, 'data');

        $otherUser = \App\Models\User::factory()->create();
        $otherPlan = Plan::factory()->create(['max_profiles' => 4]);
        $otherSub = Subscription::factory()->create(['user_id' => $otherUser->id, 'plan_id' => $otherPlan->id]);
        $otherProfile = Profile::factory()->create(['subscription_id' => $otherSub->id]);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->putJson('/api/v1/progress', ['title_id' => $movie->id, 'position_sec' => 10, 'duration_sec' => 100])
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $this->actingAs($otherUser, 'sanctum')->withHeader('X-Profile-Id', (string) $otherProfile->id)
            ->getJson('/api/v1/progress/continue-watching')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_progress_validates_and_marks_completed(): void
    {
        [$user, $token, $profile] = $this->subscriberWithProfile();
        $movie = Title::factory()->create(['type' => 'movie']);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/v1/progress', ['position_sec' => 10, 'duration_sec' => 100])
            ->assertStatus(422);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $otherProfileId = (string) 9999)
            ->putJson('/api/v1/progress', ['title_id' => $movie->id, 'position_sec' => 10, 'duration_sec' => 100])
            ->assertNotFound();

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/v1/progress', ['title_id' => $movie->id, 'position_sec' => 95, 'duration_sec' => 100])
            ->assertOk()->assertJsonPath('data.completed', true)->assertJsonPath('data.percent', 95);

        $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/progress/continue-watching')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_streams_require_auth_and_subscription(): void
    {
        $this->getJson('/api/v1/titles/any/streams')->assertUnauthorized();
        $this->getJson('/api/v1/episodes/1/streams')->assertUnauthorized();
        $this->postJson('/api/v1/catalog/resolve', ['slug' => 'x'])->assertUnauthorized();
        $this->putJson('/api/v1/progress', ['title_id' => 1, 'position_sec' => 0, 'duration_sec' => 100])->assertUnauthorized();
    }

    public function test_iptv_vod_hls_and_nested_resources_use_the_configured_proxy(): void
    {
        [, $token, $profile] = $this->subscriberWithProfile();
        $movie = Title::factory()->create([
            'slug' => 'pelicula-vod',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/master.m3u8',
            'languages' => ['Latino'],
        ]);
        app(SyncSettings::class)->put('iptv.proxies', [[
            'id' => 'proxy-main',
            'name' => 'Proxy principal',
            'base_url' => 'https://proxy.example/fetch?token=secret',
            'enabled' => true,
            'priority' => 1,
        ]]);

        Http::fake(function (ClientRequest $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $target = $query['url'] ?? '';

            return match ($target) {
                'https://cdn.example/movies/master.m3u8' => Http::response(
                    "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\nvideo/720p.m3u8\n",
                    200,
                    ['Content-Type' => 'application/vnd.apple.mpegurl'],
                ),
                'https://cdn.example/movies/video/720p.m3u8' => Http::response(
                    "#EXTM3U\n#EXTINF:6,\nsegment-001.ts\n",
                    200,
                    ['Content-Type' => 'application/vnd.apple.mpegurl'],
                ),
                default => Http::response('not found', 404),
            };
        });

        $streamUrl = $this->withToken($token)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/titles/pelicula-vod/streams')
            ->assertOk()
            ->assertJsonPath('data.0.mp4', null)
            ->json('data.0.hls');

        $this->assertStringContainsString("/api/v1/vod/title/{$movie->id}/stream", $streamUrl);
        $this->assertStringNotContainsString('proxy.example', $streamUrl);
        $manifestPath = parse_url($streamUrl, PHP_URL_PATH).'?'.parse_url($streamUrl, PHP_URL_QUERY);
        $manifest = $this->withHeader('Origin', 'http://localhost:3000')->get($manifestPath)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

        $variantUrl = collect(preg_split('/\r?\n/', $manifest->getContent()))
            ->first(fn (string $line): bool => str_starts_with($line, 'http'));
        $this->assertIsString($variantUrl);
        parse_str((string) parse_url($variantUrl, PHP_URL_QUERY), $variantQuery);
        $this->assertSame('https://cdn.example/movies/video/720p.m3u8', $variantQuery['target']);

        $variantPath = parse_url($variantUrl, PHP_URL_PATH).'?'.parse_url($variantUrl, PHP_URL_QUERY);
        $this->get($variantPath)->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://proxy.example/fetch?')
                && ($query['token'] ?? null) === 'secret'
                && ($query['url'] ?? null) === 'https://cdn.example/movies/video/720p.m3u8';
        });
    }

    public function test_iptv_vod_direct_video_uses_the_mp4_stream_field(): void
    {
        [, $token, $profile] = $this->subscriberWithProfile();
        $movie = Title::factory()->create([
            'slug' => 'pelicula-vod-mp4',
            'type' => 'movie',
            'source' => 'iptv_vod',
            'is_active' => true,
            'stream_url' => 'https://cdn.example/movies/pelicula.mp4?token=source',
        ]);

        $response = $this->withToken($token)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->getJson('/api/v1/titles/pelicula-vod-mp4/streams')
            ->assertOk()
            ->assertJsonPath('data.0.hls', null);

        $this->assertStringContainsString(
            "/api/v1/vod/title/{$movie->id}/stream",
            $response->json('data.0.mp4'),
        );
    }

    private function subscriberWithProfile(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['max_profiles' => 4]);
        $sub = Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])->json('data.token');
        $profile = Profile::factory()->create(['subscription_id' => $sub->id]);

        return [$user, $token, $profile];
    }
}
