<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Models\Setting;
use App\Models\User;
use App\Services\Iptv\IptvStreamVerifier;
use App\Services\IptvOrg\IptvOrgSyncService;
use App\Services\SyncProgressService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminWebAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_backend_root_opens_the_admin_login(): void
    {
        $this->get('/')->assertRedirect('/admin/login');
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Inicia sesion como admin.');
    }

    public function test_unauthenticated_users_cannot_open_the_admin_panel(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');

        $subscriber = User::factory()->create();
        $this->actingAs($subscriber, 'web')
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_only_an_admin_can_login_to_the_web_panel(): void
    {
        $subscriber = User::factory()->create([
            'email' => 'subscriber-web@test.test',
        ]);

        $this->from('/admin/login')
            ->post('/admin/login', [
                'login' => $subscriber->email,
                'password' => 'password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('login');

        $admin = User::factory()->admin()->create([
            'email' => 'admin-web@test.test',
        ]);

        $this->post('/admin/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'web');
        $this->get('/admin?section=fallback')
            ->assertOk()
            ->assertSee('Fuentes de streams')
            ->assertSee('Verificar e instalar addon');

        $this->post('/admin/logout')
            ->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_admin_can_save_backend_settings_without_the_frontend(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')
            ->put('/admin/stream-fallback', [
                'enabled' => '1',
                'primary' => '0',
                'languages_csv' => 'latino, español',
                'timeout_seconds' => 12,
                'cache_ttl_seconds' => 900,
            ])
            ->assertRedirect('/admin?section=fallback')
            ->assertSessionHas('success', 'Configuracion de Stremio guardada.');

        $this->assertSame(
            ['latino', 'español'],
            json_decode((string) Setting::query()->where('key', 'stremio.languages')->value('value'), true),
        );
        $this->assertTrue((bool) json_decode((string) Setting::query()->where('key', 'stremio.enabled')->value('value'), true));
    }

    public function test_admin_can_create_a_subscriber_from_the_web_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')
            ->get('/admin?section=users')
            ->assertOk()
            ->assertSee('Agregar suscriptor')
            ->assertSee('Crear suscriptor');

        $this->actingAs($admin, 'web')
            ->post('/admin/users', [
                'name' => 'Suscriptor web',
                'username' => 'suscriptor_web',
                'email' => 'suscriptor-web@test.test',
                'password' => 'secreto-456',
                'password_confirmation' => 'secreto-456',
                'duration_days' => 45,
                'group_number' => 3,
            ])
            ->assertRedirect('/admin?section=users')
            ->assertSessionHas('success', 'Suscriptor creado.');

        $subscriber = User::query()->where('username', 'suscriptor_web')->firstOrFail();
        $this->assertSame('subscriber', $subscriber->role);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $subscriber->id,
            'status' => 'active',
            'group_number' => 3,
            'created_by' => $admin->id,
        ]);
    }

    public function test_every_admin_section_renders_from_laravel(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            'overview', 'users', 'subscriptions', 'plans', 'channels',
            'iptv-playlists', 'iptv-vod-playlists', 'iptv-proxies', 'fallback',
            'stremio-catalog', 'stremio-streams', 'trials',
        ] as $section) {
            $this->actingAs($admin, 'web')
                ->get('/admin?section='.$section)
                ->assertOk();
        }
    }

    public function test_admin_can_read_sync_progress(): void
    {
        $admin = User::factory()->admin()->create();
        $progress = app(SyncProgressService::class);
        $state = $progress->start('test_sync', 'Sincronización de prueba');
        $progress->running($state['id'], 10, 'Procesando elementos.');
        $progress->update($state['id'], 5, 10, 'Procesando elementos.');

        $this->actingAs($admin, 'web')
            ->getJson('/admin/sync-status/'.$state['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.current', 5)
            ->assertJsonPath('data.total', 10)
            ->assertJsonPath('data.percentage', 50);

        $progress->complete($state['id']);
    }

    public function test_admin_web_iptv_sync_passes_the_stream_verifier(): void
    {
        $admin = User::factory()->admin()->create();
        $controller = \Mockery::mock(AdminController::class);
        $controller->shouldReceive('syncIptvPlaylists')
            ->once()
            ->with(
                \Mockery::type(IptvOrgSyncService::class),
                \Mockery::type(IptvStreamVerifier::class),
            )
            ->andReturn(response()->json(['data' => [
                'queued' => true,
                'sync_id' => 'sync-iptv-test',
                'sync_type' => 'iptv',
            ]], 202));
        $this->app->instance(AdminController::class, $controller);

        $this->actingAs($admin, 'web')
            ->post('/admin/iptv-playlists/sync')
            ->assertRedirect('/admin?section=iptv-playlists')
            ->assertSessionHas('success', 'Sincronizacion IPTV solicitada.')
            ->assertSessionHas('sync_id', 'sync-iptv-test');
    }
}
