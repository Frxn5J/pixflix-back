<?php

namespace Tests\Feature;

use App\Jobs\RefreshIptvResourcesJob;
use App\Jobs\SyncIptvJob;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueAndCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_redis_read_timeout_exceeds_the_queue_blocking_window(): void
    {
        $this->assertGreaterThan(
            (float) config('queue.connections.redis.block_for'),
            (float) config('database.redis.default.read_timeout'),
        );
    }

    public function test_admin_sync_endpoints_are_synchronous_by_default(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('upstream-down', 503)]);
        [, $token] = $this->admin();

        $this->withToken($token)->postJson('/api/v1/admin/iptv-playlists/sync')
            ->assertStatus(502); // upstream failure, handled inline

        Queue::assertNotPushed(SyncIptvJob::class);
    }

    public function test_admin_sync_endpoints_can_be_queued(): void
    {
        config(['pixflix.sync.async' => true]);
        Queue::fake();
        [, $token] = $this->admin();

        $this->withToken($token)->postJson('/api/v1/admin/iptv-playlists/sync')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.sync_type', 'iptv')
            ->assertJsonStructure(['data' => ['sync_id']]);

        $this->withToken($token)->postJson('/api/v1/admin/iptv-resources/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(SyncIptvJob::class);
        Queue::assertPushed(RefreshIptvResourcesJob::class);
    }

    public function test_catalog_detail_is_cached_until_the_stamp_changes(): void
    {
        config(['pixflix.cache.catalog_ttl' => 60]);
        [, $token] = $this->admin();
        $title = Title::factory()->create(['title' => 'Detalle Cache', 'is_active' => true]);

        $this->withToken($token)->getJson("/api/v1/titles/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Detalle Cache');

        // No stamp change -> cached payload is still served.
        $title->update(['title' => 'Detalle Renombrado']);
        $this->withToken($token)->getJson("/api/v1/titles/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Detalle Cache');

        // Bumping the stamp (as syncs do) serves the fresh payload.
        Cache::forever('pixflix:catalog-stamp', 'changed');
        $this->withToken($token)->getJson("/api/v1/titles/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Detalle Renombrado');
    }

    public function test_unknown_titles_are_not_cached(): void
    {
        config(['pixflix.cache.catalog_ttl' => 60]);
        [, $token] = $this->admin();

        $this->withToken($token)->getJson('/api/v1/titles/no-existe')
            ->assertNotFound();

        $title = Title::factory()->create(['title' => 'Aparece Despues', 'is_active' => true]);
        $this->withToken($token)->getJson("/api/v1/titles/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Aparece Despues');
    }

    private function admin(): array
    {
        $user = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return [$user, $token];
    }
}
