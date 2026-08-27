<?php

namespace Database\Factories;

use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Episode> */
class EpisodeFactory extends Factory
{
    protected $model = Episode::class;

    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'number' => 1,
            'title' => fake()->sentence(3),
            'url' => null,
            'image' => null,
            'release_date' => '2025',
            'extract_url' => null,
            'streams' => null,
        ];
    }
}
