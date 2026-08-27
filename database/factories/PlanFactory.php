<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'price' => 99.00,
            'max_profiles' => 4,
            'max_devices' => 2,
            'max_quality' => '1080p',
            'is_active' => true,
            'description' => 'Plan de prueba.',
        ];
    }
}
