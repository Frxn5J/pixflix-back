<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IptvOrgSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_imports_channels_from_iptv_org_m3u_playlist(): void
    {
        Http::fake([
            'https://iptv-org.github.io/iptv/index.m3u' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="News.mx@SD" tvg-logo="https://example.test/news.svg" tvg-language="spa" group-title="News;Public",Noticias MX
https://example.test/news.m3u8
#EXTINF:-1 tvg-id="Closed.mx@SD" group-title="General",Cerrado
https://example.test/closed.m3u8
M3U),
        ]);

        $this->artisan('pixflix:sync-iptv', ['--country' => 'MX'])->assertSuccessful();

        $this->assertDatabaseHas('channels', [
            'external_id' => 'News.mx@SD',
            'name' => 'Noticias MX',
            'country' => 'MX',
            'language' => 'spa',
            'stream_url' => 'https://example.test/news.m3u8',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('channels', [
            'external_id' => 'Closed.mx@SD',
            'category' => 'General',
            'country' => 'MX',
        ]);
    }
}
