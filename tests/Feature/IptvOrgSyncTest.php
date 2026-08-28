<?php

namespace Tests\Feature;

use App\Services\SyncSettings;
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

    public function test_sync_uses_multiple_configured_playlists_and_their_optional_filters(): void
    {
        $this->app->make(SyncSettings::class)->put('iptv.playlists', [
                [
                    'id' => 'mx-spanish',
                    'name' => 'México en español',
                    'url' => 'https://example.test/mx.m3u',
                    'country' => 'MX',
                    'language' => 'spa',
                    'enabled' => true,
                    'priority' => 1,
                ],
                [
                    'id' => 'all',
                    'name' => 'Sin filtros',
                    'url' => 'https://example.test/pluto-live-MX.m3u',
                    'country' => null,
                    'language' => null,
                    'enabled' => true,
                    'priority' => 2,
                ],
        ]);

        Http::fake([
            'https://example.test/mx.m3u' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="MX.spa" tvg-country="MX" tvg-language="spa",MX Español
https://example.test/mx.m3u8
#EXTINF:-1 tvg-id="MX.eng" tvg-country="MX" tvg-language="eng",MX English
https://example.test/mx-eng.m3u8
M3U),
            'https://example.test/pluto-live-MX.m3u' => Http::response(<<<'M3U'
#EXTM3U
#EXTINF:-1 tvg-id="pluto-1" group-title="Noticias",Canal Pluto MX
https://example.test/us.m3u8
M3U),
        ]);

        $this->artisan('pixflix:sync-iptv')->assertSuccessful();

        $this->assertDatabaseHas('channels', ['external_id' => 'MX.spa']);
        $this->assertDatabaseMissing('channels', ['external_id' => 'MX.eng']);
        $this->assertDatabaseHas('channels', ['external_id' => 'pluto-1', 'country' => 'MX']);
    }
}
