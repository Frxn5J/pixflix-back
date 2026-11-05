<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalVideoTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Esta cuenta solo puede crearse en el entorno local.');
        }

        $user = User::query()->firstOrCreate(['email' => 'demo@pixflix.local'], [
            'name' => 'Demo Pixflix', 'password' => Hash::make('PixflixDemo2026!'), 'role' => 'subscriber',
        ]);
        $plan = Plan::query()->firstOrCreate(['name' => 'Prueba local'], [
            'price' => 0, 'max_profiles' => 4, 'max_devices' => 2, 'max_quality' => '1080p', 'is_active' => true,
        ]);
        $subscription = Subscription::query()->firstOrCreate(['user_id' => $user->id], [
            'plan_id' => $plan->id, 'status' => 'active', 'is_trial' => false, 'group_number' => 1, 'starts_at' => now(),
        ]);
        Profile::query()->firstOrCreate(['subscription_id' => $subscription->id, 'name' => 'Principal'], ['is_kids' => false]);
    }
}
