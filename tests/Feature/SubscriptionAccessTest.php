<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_me_reports_subscription_states_correctly(): void
    {
        $active = User::factory()->create(['email' => 'active-state@test.test']);
        Subscription::factory()->create(['user_id' => $active->id, 'status' => 'active']);
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'active-state@test.test', 'password' => 'password'])->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.access_allowed', true)
            ->assertJsonPath('data.group_number', fn ($v) => is_int($v) && $v >= 1 && $v <= 7);
    }

    public function test_login_rejects_inactive_subscription_statuses(): void
    {
        foreach (['expired', 'suspended', 'cancelled', 'pending'] as $status) {
            $email = "inactive-{$status}@test.test";
            $user = User::factory()->create(['email' => $email]);
            Subscription::factory()->create(['user_id' => $user->id, 'status' => $status, 'ends_at' => $status === 'expired' ? now()->subDay() : now()->addDay()]);
            $this->postJson('/api/v1/auth/login', ['login' => $email, 'password' => 'password'])
                ->assertForbidden()->assertJsonPath('error.code', 'subscription_inactive');
        }
    }

    public function test_trial_expired_blocks_catalog_and_me(): void
    {
        $user = User::factory()->create(['email' => 'trial-exp@test.test']);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => 'trial',
            'is_trial' => true,
            'trial_expires_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
        ]);
        $this->postJson('/api/v1/auth/login', ['login' => 'trial-exp@test.test', 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('error.code', 'trial_expired');

        $activeTrial = User::factory()->create(['email' => 'trial-active2@test.test']);
        Subscription::factory()->trial()->create(['user_id' => $activeTrial->id]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'trial-active2@test.test', 'password' => 'password'])->json('data.token');
        $activeTrial->subscriptions()->update(['trial_expires_at' => now()->subMinute(), 'ends_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/catalog')->assertForbidden()->assertJsonPath('error.code', 'trial_expired');
        $this->withToken($token)->getJson('/api/v1/profiles')->assertForbidden()->assertJsonPath('error.code', 'trial_expired');
    }

    public function test_admin_and_agent_can_access_without_subscription(): void
    {
        $admin = User::factory()->create(['email' => 'admin@test.test', 'role' => 'admin']);
        $adminToken = $this->postJson('/api/v1/auth/login', ['login' => 'admin@test.test', 'password' => 'password'])->json('data.token');
        $this->withToken($adminToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'admin');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/catalog')->assertOk();

        $agent = User::factory()->create(['email' => 'agent@test.test', 'role' => 'agent']);
        $this->postJson('/api/v1/auth/login', ['login' => 'agent@test.test', 'password' => 'password'])->assertOk();
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'agent');
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/catalog')->assertOk();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->getJson('/api/v1/profiles')->assertUnauthorized();
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_login_with_phone_and_username(): void
    {
        $user = User::factory()->create(['email' => 'phone@test.test', 'phone' => '5551002000', 'username' => 'pixuser']);
        Subscription::factory()->create(['user_id' => $user->id]);
        $this->postJson('/api/v1/auth/login', ['login' => '5551002000', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'pixuser', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'phone@test.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'wrong', 'password' => 'password'])->assertUnauthorized();
    }
}
