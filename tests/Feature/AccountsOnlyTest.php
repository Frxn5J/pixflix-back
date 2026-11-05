<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_only_mode_disables_legacy_video_routes_and_admin_controls(): void
    {
        if (! config('pixflix.accounts_only')) {
            $this->markTestSkipped('Run with PIXFLIX_ACCOUNTS_ONLY=true.');
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin')
            ->assertOk()->assertSee('Usuarios')->assertDontSee('VOD Stremio')->assertDontSee('Listas IPTV');
        $this->actingAs($admin)->putJson('/api/v1/admin/iptv-playlists', [])->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/catalog')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/channels')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();
    }
}
