<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Services\Iptv\IptvStreamVerifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IptvStreamVerifierTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'pixflix.iptv.verifier.attempts' => 1,
            'pixflix.iptv.verifier.require_cors' => true,
            'pixflix.iptv.verifier.timeout_seconds' => 3,
            'pixflix.iptv.verifier.connect_timeout_seconds' => 1,
        ]);
    }

    public function test_forbidden_manifest_deactivates_a_channel(): void
    {
        $channel = Channel::query()->create([
            'name' => 'National Geographic',
            'country' => 'MX',
            'category' => 'Documentary',
            'stream_url' => 'https://example.test/natgeo/index.m3u8',
            'use_proxy' => false,
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.test/*' => Http::response('Forbidden', 403, [
                'Access-Control-Allow-Origin' => '*',
            ]),
        ]);

        $result = app(IptvStreamVerifier::class)->run('MX');

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['healthy']);
        $this->assertSame(1, $result['deactivated']);
        $this->assertSame(1, $result['failures']['http_403']);
        $this->assertFalse($channel->fresh()->is_active);
        $this->assertSame('failed', $channel->fresh()->stream_check_status);
    }

    public function test_hls_channel_requires_a_live_variant_and_segment(): void
    {
        $channel = Channel::query()->create([
            'name' => 'Distrito Comedia',
            'country' => 'MX',
            'category' => 'Entertainment',
            'stream_url' => 'https://example.test/distrito/index.m3u8',
            'use_proxy' => false,
            'is_active' => true,
        ]);

        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://example.test/distrito/index.m3u8' => Http::response(
                    "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100000\ntracks/mono.m3u8\n",
                    200,
                    [
                        'Content-Type' => 'application/vnd.apple.mpegurl',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ),
                'https://example.test/distrito/tracks/mono.m3u8' => Http::response(
                    "#EXTM3U\n#EXTINF:6,\nsegments/first.ts\n",
                    200,
                    [
                        'Content-Type' => 'application/vnd.apple.mpegurl',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ),
                'https://example.test/distrito/tracks/segments/first.ts' => Http::response(
                    "\x00\x00\x01\xBD\x00\x01\x02\x03",
                    206,
                    [
                        'Content-Type' => 'video/mp2t',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ),
                default => Http::response('Not found', 404),
            };
        });

        $result = app(IptvStreamVerifier::class)->run('MX');

        $this->assertSame(1, $result['healthy']);
        $this->assertSame(0, $result['deactivated']);
        $this->assertTrue($channel->fresh()->is_active);
        $this->assertSame('healthy', $channel->fresh()->stream_check_status);
    }

    public function test_missing_first_segment_deactivates_an_hls_channel(): void
    {
        $channel = Channel::query()->create([
            'name' => 'Canal con segmento caido',
            'country' => 'MX',
            'category' => 'General',
            'stream_url' => 'https://example.test/broken/index.m3u8',
            'use_proxy' => false,
            'is_active' => true,
        ]);

        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://example.test/broken/index.m3u8' => Http::response(
                    "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100000\ntracks/mono.m3u8\n",
                    200,
                    [
                        'Content-Type' => 'application/vnd.apple.mpegurl',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ),
                'https://example.test/broken/tracks/mono.m3u8' => Http::response(
                    "#EXTM3U\n#EXTINF:6,\nsegments/missing.ts\n",
                    200,
                    [
                        'Content-Type' => 'application/vnd.apple.mpegurl',
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ),
                'https://example.test/broken/tracks/segments/missing.ts' => Http::response('Missing', 404),
                default => Http::response('Not found', 404),
            };
        });

        $result = app(IptvStreamVerifier::class)->run('MX');

        $this->assertSame(0, $result['healthy']);
        $this->assertSame(1, $result['deactivated']);
        $this->assertSame('http_404', $channel->fresh()->stream_check_error);
        $this->assertFalse($channel->fresh()->is_active);
    }

    public function test_direct_stream_without_cors_is_not_playable_in_the_browser(): void
    {
        $channel = Channel::query()->create([
            'name' => 'Canal sin CORS',
            'country' => 'MX',
            'category' => 'General',
            'stream_url' => 'https://example.test/no-cors/index.m3u8',
            'use_proxy' => false,
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.test/no-cors/index.m3u8' => Http::response(
                "#EXTM3U\n#EXTINF:6,\nhttps://example.test/no-cors/live.ts\n",
                200,
                ['Content-Type' => 'application/vnd.apple.mpegurl'],
            ),
        ]);

        $result = app(IptvStreamVerifier::class)->run('MX');

        $this->assertSame(1, $result['deactivated']);
        $this->assertSame('cors_missing', $channel->fresh()->stream_check_error);
    }
}
