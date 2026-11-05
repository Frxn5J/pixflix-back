<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalProfileMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_use_external_ids_and_are_isolated_by_profile(): void
    {
        [$token, $profile] = $this->subscriber();
        [$otherToken] = $this->subscriber();
        $url = "/api/v1/profiles/{$profile->id}/external-favorites";
        $content = $this->content();

        $this->withToken($token)->putJson($url, $content)->assertOk();
        $this->withToken($token)->putJson($url, $content)->assertOk();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.content_id', 'movie:abc');
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->getJson($url)->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($token)->deleteJson($url, ['provider' => 'backend2', 'content_id' => 'movie:abc'])
            ->assertOk()->assertJsonPath('data.deleted', true);
        $this->withToken($token)->getJson($url)->assertJsonCount(0, 'data');
    }

    public function test_progress_is_separate_per_episode_and_completed_items_are_excluded(): void
    {
        [$token, $profile] = $this->subscriber();
        $url = "/api/v1/profiles/{$profile->id}/external-progress";
        $base = [...$this->content(), 'position_sec' => 100, 'duration_sec' => 1000];
        $this->withToken($token)->putJson($url, [...$base, 'episode_id' => 'episode:1'])->assertOk();
        $this->withToken($token)->putJson($url, [...$base, 'episode_id' => 'episode:2'])->assertOk();
        $this->withToken($token)->putJson($url, [...$base, 'episode_id' => 'episode:1', 'position_sec' => 950])
            ->assertOk()->assertJsonPath('data.completed', true);
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.episode_id', 'episode:2');
    }

    private function content(): array
    {
        return ['provider' => 'backend2', 'content_id' => 'movie:abc', 'content' => [
            'column_id' => '76177', 'type' => 'movie', 'title' => 'Película',
        ]];
    }

    private function subscriber(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $profile = Profile::factory()->create(['subscription_id' => $subscription->id]);
        $token = $user->createToken('test')->plainTextToken;

        return [$token, $profile];
    }
}
