<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_read_overview_and_manage_core_records(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['name' => 'Cuenta administrada']);
        $subscription = Subscription::factory()->create(['user_id' => $target->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.users', fn (int $value) => $value >= 2);

        $this->withToken($token)->putJson("/api/v1/admin/users/{$target->id}", [
            'role' => 'agent',
        ])->assertOk()->assertJsonPath('data.role', 'agent');

        $this->withToken($token)->putJson("/api/v1/admin/subscriptions/{$subscription->id}", [
            'status' => 'suspended',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->withToken($token)->postJson('/api/v1/admin/plans', [
            'name' => 'Panel Pro',
            'price' => 199,
            'max_profiles' => 5,
            'max_devices' => 3,
            'max_quality' => '1080p',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.name', 'Panel Pro');
    }

    public function test_subscriber_cannot_access_admin_endpoints(): void
    {
        $subscriber = User::factory()->create(['email' => 'admin-denied@test.test']);
        Subscription::factory()->create(['user_id' => $subscriber->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $subscriber->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_admin_can_manage_iptv_proxy_pool(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/admin/iptv-proxies', [
            'proxies' => [
                [
                    'id' => 'proxy-main',
                    'name' => 'Proxy principal',
                    'base_url' => 'https://proxy.example/?token=secret@123',
                    'enabled' => true,
                    'priority' => 2,
                ],
                [
                    'id' => 'proxy-backup',
                    'name' => 'Proxy respaldo',
                    'base_url' => 'https://backup.example/?token=backup',
                    'enabled' => false,
                    'priority' => 1,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.proxies.0.id', 'proxy-backup')
            ->assertJsonPath('data.proxies.1.base_url', 'https://proxy.example/?token=secret@123');

        $this->withToken($token)->getJson('/api/v1/admin/iptv-proxies')
            ->assertOk()
            ->assertJsonPath('data.proxies.0.enabled', false)
            ->assertJsonPath('data.proxies.1.name', 'Proxy principal');
    }
}
