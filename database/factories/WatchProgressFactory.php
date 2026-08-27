<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\WatchProgress> */
class WatchProgressFactory extends Factory
{
    protected $model = \App\Models\WatchProgress::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'title_id' => Title::factory(),
            'episode_id' => null,
            'season_id' => null,
            'position_sec' => fake()->numberBetween(30, 600),
            'duration_sec' => 3600,
            'percent' => fake()->randomFloat(2, 5, 80),
            'completed' => false,
        ];
    }
}
