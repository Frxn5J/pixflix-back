<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    protected $model = Season::class;

    public function definition(): array
    {
        return [
            'title_id' => Title::factory(),
            'number' => 1,
            'title' => 'Temporada 1',
            'release_date' => '2025',
        ];
    }
}
