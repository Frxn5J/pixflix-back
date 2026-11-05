<?php

namespace Tests\Feature;

use App\Models\Episode;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Season;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WatchProgressTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_episode_of_a_series_keeps_its_own_progress(): void
    {
        [$token, $profile] = $this->subscriber();
        $series = Title::factory()->create(['type' => 'tvshow']);
        $season = Season::factory()->create(['title_id' => $series->id]);
        $first = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $second = Episode::factory()->create(['season_id' => $season->id, 'number' => 2]);

        foreach ([[$first, 100], [$second, 250], [$first, 300]] as [$episode, $position]) {
            $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
                ->putJson('/api/v1/progress', [
                    'episode_id' => $episode->id,
                    'position_sec' => $position,
                    'duration_sec' => 2000,
                ])
                ->assertOk()
                ->assertJsonPath('data.episode_id', $episode->id);
        }

        $this->assertSame(2, WatchProgress::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(300, WatchProgress::query()->where('episode_id', $first->id)->value('position_sec'));
    }

    public function test_a_movie_still_has_a_single_progress_row_per_profile(): void
    {
        [$token, $profile] = $this->subscriber();
        $movie = Title::factory()->create(['type' => 'movie']);

        foreach ([100, 400] as $position) {
            $this->withToken($token)->withHeader('X-Profile-Id', (string) $profile->id)
                ->putJson('/api/v1/progress', [
                    'title_id' => $movie->id,
                    'position_sec' => $position,
                    'duration_sec' => 6000,
                ])->assertOk();
        }

        $this->assertSame(1, WatchProgress::query()->where('title_id', $movie->id)->count());
        $this->assertSame(400, WatchProgress::query()->where('title_id', $movie->id)->value('position_sec'));
    }

    /** @return array{0: string, 1: Profile} */
    private function subscriber(): array
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['max_profiles' => 2]);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => $plan->id]);
        $profile = Profile::factory()->create(['subscription_id' => $subscription->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        return [$token, $profile];
    }
}
