<?php

namespace Database\Factories;

use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Title> */
class TitleFactory extends Factory
{
    protected $model = Title::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(2);

        return [
            'external_id' => fake()->unique()->numerify('title-#####'),
            'slug' => fake()->unique()->slug(2),
            'type' => 'movie',
            'title' => $title,
            'description' => fake()->paragraph(),
            'poster' => null,
            'gallery' => [],
            'rating' => '8.0',
            'year' => (string) fake()->numberBetween(2018, 2026),
            'quality' => '1080p',
            'languages' => ['Latino'],
            'genres' => ['Drama'],
            'category' => 'normal',
            'total_seasons' => null,
            'total_episodes' => null,
            'raw_extract' => null,
            'snapshot_version' => null,
        ];
    }
}
