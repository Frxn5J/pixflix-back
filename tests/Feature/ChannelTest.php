<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\EpgEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
