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
            ->assertJsonPath('data.stream.hls', 'https://cdn.test/demo.m3u8')
            ->assertJsonPath('data.stream.proxy.required', true)
            ->assertJsonPath('data.stream.mp4', null);

        $this->withToken($token)->getJson("/api/v1/channels/{$channel->id}/epg")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Programa actual');
    }

    public function test_channel_delivers_the_configured_proxy_pool_to_the_frontend(): void
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
        Cache::forget('pixflix:setting:iptv.proxies');
        $response = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk();

        $response->assertJsonPath('data.stream.hls', 'https://cdn.test/proxy.m3u8');
        $response->assertJsonPath('data.stream.proxy.required', true);
        $response->assertJsonPath('data.stream.proxy.proxies.0.id', 'proxy-one');
        $response->assertJsonPath('data.stream.proxy.proxies.1.id', 'proxy-two');
    }

    public function test_channel_can_bypass_the_proxy_when_its_playlist_disables_it(): void
    {
        [$token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal directo',
            'category' => 'Noticias',
            'stream_url' => 'https://cdn.test/direct.m3u8',
            'source_playlist_id' => 'playlist-directa',
            'use_proxy' => false,
            'is_active' => true,
        ]);
        Setting::query()->create([
            'key' => 'iptv.proxies',
            'value' => json_encode([[
                'id' => 'proxy-one',
                'name' => 'Proxy uno',
                'base_url' => 'https://proxy-one.test/?token=one',
                'enabled' => true,
                'priority' => 1,
            ]]),
        ]);
        Cache::forget('pixflix:setting:iptv.proxies');

        $streamUrl = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk()
            ->assertJsonPath('data.stream.hls', 'https://cdn.test/direct.m3u8')
            ->assertJsonPath('data.stream.proxy.required', false)
            ->json('data.stream.hls');

        $this->assertSame('https://cdn.test/direct.m3u8', $streamUrl);
    }

    public function test_channel_metadata_does_not_fetch_or_rewrite_the_manifest(): void
    {
        [$token] = $this->subscriber();
        $channel = Channel::query()->create([
            'name' => 'Canal con manifiesto reescrito',
            'category' => 'Noticias',
            'stream_url' => 'https://cdn.test/master.m3u8',
            'is_active' => true,
        ]);
        Setting::query()->create([
            'key' => 'iptv.proxies',
            'value' => json_encode([[
                'id' => 'proxy-one',
                'name' => 'Proxy uno',
                'base_url' => 'https://proxy-one.test/?token=secret',
                'enabled' => true,
                'priority' => 1,
            ]]),
        ]);
        Cache::forget('pixflix:setting:iptv.proxies');

        $response = $this->withToken($token)
            ->getJson("/api/v1/channels/{$channel->id}")
            ->assertOk();

        $response->assertJsonPath('data.stream.hls', 'https://cdn.test/master.m3u8');
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
