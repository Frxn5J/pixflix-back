<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stateful_spa_login_authenticates_the_session(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $user = User::factory()->create([
            'email' => 'spa@pixflix.test',
            'password' => Hash::make('password'),
        ]);
        Subscription::factory()->create(['user_id' => $user->id]);

        $spa = $this->withHeader('Origin', 'http://localhost:3000');

        $loginResponse = $spa->postJson('/api/v1/auth/login', [
            'login' => 'spa@pixflix.test',
            'password' => 'password',
        ])->assertOk();

        $spaToken = $loginResponse->json('data.token');
        $this->assertNotEmpty($spaToken);
        $this->assertAuthenticatedAs($user);

        $spa->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $spa->withToken($spaToken)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);

        $this->refreshApplication();
        $this->withToken($spaToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_active_subscriber_can_login_and_read_me(): void
    {
        $user = User::factory()->create([
            'email' => 'active@pixflix.test',
            'password' => Hash::make('password'),
        ]);

        Subscription::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'group_number' => 3,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'active@pixflix.test',
            'password' => 'password',
        ]);

        $login->assertOk()->assertJsonStructure([
            'data' => ['token', 'token_type', 'user'],
        ]);

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.access_allowed', true)
            ->assertJsonPath('data.group_number', 3)
            ->assertJsonPath('data.pwa.force_update', false)
            ->assertJsonPath('data.profiles', []);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@pixflix.test',
            'password' => Hash::make('password'),
        ]);
        Subscription::factory()->create(['user_id' => $user->id]);

        $token = $this->postJson('/api/v1/auth/login', [
            'login' => 'logout@pixflix.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->refreshApplication();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_update_hashes_the_new_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'password@pixflix.test',
            'password' => Hash::make('password'),
        ]);
        Subscription::factory()->create(['user_id' => $user->id]);

        $token = $this->postJson('/api/v1/auth/login', [
            'login' => 'password@pixflix.test',
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'password@pixflix.test',
            'password' => 'new-password',
        ])->assertOk();

        $this->refreshApplication();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expired_and_suspended_subscribers_cannot_login(): void
    {
        foreach (['expired', 'suspended'] as $status) {
            $email = $status.'@pixflix.test';
            $user = User::factory()->create([
                'email' => $email,
                'password' => Hash::make('password'),
            ]);
            Subscription::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
            ]);

            $this->postJson('/api/v1/auth/login', [
                'login' => $email,
                'password' => 'password',
            ])->assertForbidden()->assertJsonPath('error.code', 'subscription_inactive');
        }
    }

    public function test_active_and_expired_trials_use_their_specific_states(): void
    {
        $active = User::factory()->create([
            'email' => 'trial-active@pixflix.test',
            'password' => Hash::make('password'),
        ]);
        Subscription::factory()->trial()->create(['user_id' => $active->id]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'trial-active@pixflix.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.subscription.is_trial', true);

        $expired = User::factory()->create([
            'email' => 'trial-expired@pixflix.test',
            'password' => Hash::make('password'),
        ]);
        Subscription::factory()->create([
            'user_id' => $expired->id,
            'status' => 'trial',
            'is_trial' => true,
            'trial_expires_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'trial-expired@pixflix.test',
            'password' => 'password',
        ])->assertForbidden()->assertJsonPath('error.code', 'trial_expired');
    }
}
