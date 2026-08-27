<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\EpgEntry;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_subscriber_can_list_channels_and_read_epg(): void
    {
        [$token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal Demo',
            'category' => 'Noticias',
            'country' => 'México',
            'language' => 'Español',
            'stream_url' => 'https://cdn.test/demo.m3u8',
            'is_active' => true,
        ]);
        EpgEntry::query()->create([
            'channel_id' => $channel->id,
            'title' => 'Programa actual',
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addMinutes(50),
        ]);

        $this->withToken($token)->getJson('/api/v1/channels?category=Noticias')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Canal Demo')
            ->assertJsonPath('data.0.stream.quality', 'auto');

        $this->withToken($token)->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk()
            ->assertJsonPath('data.current.title', 'Programa actual')
            ->assertJsonPath('data.stream.quality', 'auto')
            ->assertJsonPath('data.stream.mp4', null);

        Http::fake([
            'https://cdn.test/*' => Http::response("#EXTM3U\n#EXTINF:4,\nsegment.ts\n", 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]),
        ]);
        $proxyUrl = $this->withToken($token)->getJson("/api/v1/channels/{$channel->id}")->json('data.stream.hls');
        $this->get($proxyUrl)->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');

        $this->withToken($token)->getJson("/api/v1/channels/{$channel->id}/epg")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Programa actual');
    }

    public function test_iptv_proxy_pool_rotates_and_cascades(): void
    {
        [$token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal con proxies',
            'category' => 'Noticias',
            'stream_url' => 'https://cdn.test/proxy.m3u8',
            'is_active' => true,
        ]);
        Setting::query()->create([
            'key' => 'iptv.proxies',
            'value' => json_encode([
                [
                    'id' => 'proxy-one',
                    'name' => 'Proxy uno',
                    'base_url' => 'https://proxy-one.test/?token=one',
                    'enabled' => true,
                    'priority' => 1,
                ],
                [
                    'id' => 'proxy-two',
                    'name' => 'Proxy dos',
                    'base_url' => 'https://proxy-two.test/?token=two',
                    'enabled' => true,
                    'priority' => 2,
                ],
            ]),
        ]);
        Cache::forget('pixflix:iptv-proxy-cursor');
        Http::fake([
            'https://proxy-one.test/*' => Http::response("#EXTM3U\n#EXTINF:4,\nsegment.ts\n", 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
            ]),
            'https://proxy-two.test/*' => Http::response("#EXTM3U\n#EXTINF:4,\nsegment.ts\n", 503),
        ]);

        $streamUrl = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->json('data.stream.hls');

        $this->get($streamUrl)->assertOk();
        $this->get($streamUrl)->assertOk();

        $requests = Http::recorded();
        $this->assertCount(3, $requests);
        $this->assertStringStartsWith('https://proxy-one.test/', $requests[0][0]->url());
        $this->assertStringStartsWith('https://proxy-two.test/', $requests[1][0]->url());
        $this->assertStringStartsWith('https://proxy-one.test/', $requests[2][0]->url());
        $this->assertStringContainsString(
            'url=https%3A%2F%2Fcdn.test%2Fproxy.m3u8',
            $requests[0][0]->url(),
        );
    }

    private function subscriber(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return [$token];
    }
}
