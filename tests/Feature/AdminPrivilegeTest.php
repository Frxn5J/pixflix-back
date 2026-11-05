<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPrivilegeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_agent_cannot_change_roles(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $subscriber = User::factory()->create(['role' => 'subscriber']);

        $this->asUser($agent)
            ->putJson("/api/v1/admin/users/{$agent->id}", ['role' => 'admin'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->asUser($agent)
            ->putJson("/api/v1/admin/users/{$subscriber->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('agent', $agent->refresh()->role);
        $this->assertSame('subscriber', $subscriber->refresh()->role);
    }

    public function test_agent_cannot_edit_staff_accounts(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $originalHash = $admin->password;

        $this->asUser($agent)
            ->putJson("/api/v1/admin/users/{$admin->id}", ['password' => 'hijacked-pass-1'])
            ->assertForbidden();

        $this->asUser($agent)
            ->putJson("/api/v1/admin/users/{$otherAgent->id}", ['name' => 'Otro'])
            ->assertForbidden();

        $this->assertSame($originalHash, $admin->refresh()->password);
    }

    public function test_agent_can_still_manage_subscribers(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $subscriber = User::factory()->create(['role' => 'subscriber']);

        $this->asUser($agent)
            ->putJson("/api/v1/admin/users/{$subscriber->id}", ['name' => 'Nuevo nombre', 'password' => 'otra-clave-123'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuevo nombre');

        $this->assertTrue(Hash::check('otra-clave-123', $subscriber->refresh()->password));
    }

    public function test_admin_can_change_roles_and_tokens_are_revoked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'agent']);
        $target->createToken('pwa');
        $this->assertSame(1, $target->tokens()->count());

        $this->asUser($admin)
            ->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'subscriber'])
            ->assertOk()
            ->assertJsonPath('data.role', 'subscriber');

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_password_change_by_admin_revokes_the_users_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $subscriber = User::factory()->create(['role' => 'subscriber']);
        $subscriber->createToken('pwa');

        $this->asUser($admin)
            ->putJson("/api/v1/admin/users/{$subscriber->id}", ['password' => 'una-clave-nueva-1'])
            ->assertOk();

        $this->assertSame(0, $subscriber->tokens()->count());
    }

    public function test_the_last_admin_cannot_be_demoted(): void
    {
        User::query()->where('role', 'admin')->delete();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->asUser($admin)
            ->putJson("/api/v1/admin/users/{$admin->id}", ['role' => 'subscriber'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertSame('admin', $admin->refresh()->role);
    }

    private function asUser(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
