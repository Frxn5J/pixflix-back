<?php

namespace Database\Factories;

use App\Models\CatalogSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogSnapshot> */
class CatalogSnapshotFactory extends Factory
{
    protected $model = CatalogSnapshot::class;

    public function definition(): array
    {
        return [
            'version' => fake()->unique()->numberBetween(1, 100000),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => 'success',
            'stats' => ['movies' => 0, 'tvshows' => 0, 'episodes' => 0, 'errors' => 0],
            'triggered_by' => 'manual',
        ];
    }
}
