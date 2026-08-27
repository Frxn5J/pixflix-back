<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'is_trial' => false,
            'trial_expires_at' => null,
            'group_number' => fake()->numberBetween(1, 7),
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
            'custom_price' => null,
            'created_by' => null,
            'whatsapp_ref' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'ends_at' => now()->subDay(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => 'suspended',
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn () => [
            'status' => 'trial',
            'is_trial' => true,
            'trial_expires_at' => now()->addHour(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
