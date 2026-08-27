<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_subscriber_can_create_and_read_a_sanitized_profile(): void
    {
        [$user, $token] = $this->subscriberWithPlan(2);

        $response = $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Nina',
            'avatar' => 'preset:nina',
            'is_kids' => true,
            'pin' => '1234',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nina')
            ->assertJsonPath('data.avatar_url', 'preset:nina')
            ->assertJsonPath('data.is_kids', true)
            ->assertJsonMissingPath('data.pin_hash');

        $profileId = $response->json('data.id');

        $this->assertDatabaseHas('profiles', [
            'id' => $profileId,
            'subscription_id' => $user->currentSubscription()->id,
            'is_kids' => true,
        ]);
        $this->assertTrue(Hash::check('1234', Profile::findOrFail($profileId)->pin_hash));

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.profiles.0.id', $profileId);
    }

    public function test_profile_names_are_unique_and_plan_limit_is_enforced(): void
    {
        [, $token] = $this->subscriberWithPlan(2);

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Principal',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'principal',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Segundo',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Tercero',
        ])->assertStatus(422)->assertJsonPath('error.code', 'profile_limit_reached');
    }

    public function test_profile_limit_is_enforced_when_plan_allows_one(): void
    {
        [, $token] = $this->subscriberWithPlan(1);

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Unico',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/profiles', [
            'name' => 'Otro',
        ])->assertStatus(422)->assertJsonPath('error.code', 'profile_limit_reached');
    }

    public function test_profile_operations_are_isolated_by_subscription(): void
    {
        [$owner, $ownerToken] = $this->subscriberWithPlan(4);
        $ownerSubscriptionId = $owner->currentSubscription()->id;
        $profile = Profile::factory()->create([
            'subscription_id' => $ownerSubscriptionId,
            'name' => 'Privado',
        ]);

        $otherUser = User::factory()->create();
        $otherPlan = Plan::factory()->create(['max_profiles' => 4]);
        Subscription::factory()->create(['user_id' => $otherUser->id, 'plan_id' => $otherPlan->id]);
        $otherToken = $this->postJson('/api/v1/auth/login', [
            'login' => $otherUser->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($otherToken)
            ->putJson('/api/v1/profiles/'.$profile->id, ['name' => 'Robado'])
            ->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/v1/profiles/'.$profile->id, ['name' => 'Actualizado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Actualizado');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/v1/profiles/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    public function test_profile_isolation_with_bearer_tokens_is_enforced(): void
    {
        [$owner, $ownerToken] = $this->subscriberWithPlan(4);
        $profile = Profile::factory()->create([
            'subscription_id' => $owner->currentSubscription()->id,
            'name' => 'Privado',
        ]);

        $otherUser = User::factory()->create();
        $otherPlan = Plan::factory()->create(['max_profiles' => 4]);
        Subscription::factory()->create(['user_id' => $otherUser->id, 'plan_id' => $otherPlan->id]);
        $otherToken = $this->postJson('/api/v1/auth/login', [
            'login' => $otherUser->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($otherToken)
            ->putJson('/api/v1/profiles/'.$profile->id, ['name' => 'Robado'])
            ->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/profiles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $profile->id);
        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/profiles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function subscriberWithPlan(int $maxProfiles): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['max_profiles' => $maxProfiles]);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return [$user, $token];
    }
}
