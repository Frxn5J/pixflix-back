<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundRequestGuardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_http_client_refuses_internal_destinations(): void
    {
        Http::fake();

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:6379/', 'http://postgres.internal/'] as $url) {
            try {
                Http::get($url);
                $this->fail("Se permitio la solicitud a {$url}");
            } catch (ConnectionException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_http_client_still_reaches_public_destinations(): void
    {
        Http::fake(['https://cdn.example/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://cdn.example/manifest.json')->json('ok'));
    }

    public function test_admin_cannot_save_playlists_pointing_to_internal_addresses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/admin/iptv-playlists', [
            'playlists' => [[
                'name' => 'Interna',
                'url' => 'http://169.254.169.254/latest/meta-data/',
                'enabled' => true,
                'priority' => 1,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrorFor('playlists.0.url', 'error.details');
    }
}
