<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_can_add_list_and_remove_a_favorite(): void
    {
        [$user, $token, $profile] = $this->subscriberWithProfile();
        $title = Title::factory()->create();
        $endpoint = "/api/v1/profiles/{$profile->id}/favorites";

        $this->withToken($token)->postJson($endpoint, ['title_id' => $title->id])
            ->assertCreated()
            ->assertJsonPath('data.is_favorite', true);

        $this->withToken($token)->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.0.id', $title->id);

        $this->withToken($token)
            ->deleteJson("{$endpoint}/{$title->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('favorites', [
            'profile_id' => $profile->id,
            'title_id' => $title->id,
        ]);
    }

    public function test_favorites_are_isolated_by_subscription(): void
    {
        [$user, $token, $profile] = $this->subscriberWithProfile();
        $other = $this->subscriberWithProfile();
        $title = Title::factory()->create();

        Favorite::query()->create([
            'profile_id' => $profile->id,
            'title_id' => $title->id,
        ]);

        $this->withToken($other[1])
            ->getJson("/api/v1/profiles/{$profile->id}/favorites")
            ->assertNotFound();
    }

    private function subscriberWithProfile(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['max_profiles' => 4]);
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');
        $profile = Profile::factory()->create(['subscription_id' => $subscription->id]);

        return [$user, $token, $profile];
    }
}
