<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TrialTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_a_one_hour_trial(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/v1/trials', ['name' => 'Demo']);
        $response->assertCreated()
            ->assertJsonPath('data.username', fn (string $value) => str_starts_with($value, 'trial_'));

        $credentials = $response->json('data');
        $user = User::query()->where('username', $credentials['username'])->firstOrFail();
        $subscription = $user->currentSubscription();
        $this->assertSame('trial', $subscription?->status);
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
        $this->assertEqualsWithDelta(3600, now()->diffInSeconds($subscription->trial_expires_at), 3);
    }

    public function test_expire_trials_marks_them_expired_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->trial()->create([
            'user_id' => $user->id,
            'trial_expires_at' => now()->subMinute(),
            'ends_at' => now()->subMinute(),
        ]);
        $token = $user->createToken('test')->accessToken;

        $this->artisan('pixflix:expire-trials')->assertSuccessful();

        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }
}
