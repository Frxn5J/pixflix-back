<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'name' => fake()->firstName(),
            'avatar_url' => null,
            'is_kids' => false,
            'pin_hash' => null,
        ];
    }

    public function withPin(string $pin = '1234'): static
    {
        return $this->state(fn () => ['pin_hash' => Hash::make($pin)]);
    }

    public function kids(): static
    {
        return $this->state(fn () => ['is_kids' => true]);
    }
}
