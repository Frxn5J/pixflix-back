<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminPassword = $this->adminPassword();

        $this->call(CatalogSeeder::class);
        $this->call(ChannelSeeder::class);

        $plan = Plan::query()->updateOrCreate(
            ['name' => 'Familiar'],
            [
                'price' => 149.00,
                'max_profiles' => 4,
                'max_devices' => 2,
                'max_quality' => '1080p',
                'is_active' => true,
                'description' => 'Plan demo para desarrollo local.',
            ],
        );

        $adminEmail = config('pixflix.admin.email');
        $admin = User::query()->where('email', $adminEmail)->first();

        if ($admin === null && $adminEmail !== 'admin@pixflix.test') {
            $admin = User::query()
                ->where('email', 'admin@pixflix.test')
                ->where('role', 'admin')
                ->first();
        }

        $admin ??= new User();
        $admin->forceFill([
            'name' => 'Pixflix Admin',
            'email' => $adminEmail,
            'username' => config('pixflix.admin.username'),
            'password' => Hash::make($adminPassword),
            'role' => 'admin',
        ])->save();

        User::query()->updateOrCreate(
            ['email' => 'agent@pixflix.test'],
            [
                'name' => 'Pixflix Agent',
                'password' => Hash::make('password'),
                'role' => 'agent',
            ],
        );

        $subscriber = User::query()->updateOrCreate(
            ['email' => 'subscriber@pixflix.test'],
            [
                'name' => 'Pixflix Subscriber',
                'password' => Hash::make('password'),
                'role' => 'subscriber',
            ],
        );

        $subscription = Subscription::query()->updateOrCreate(
            ['user_id' => $subscriber->id],
            [
                'plan_id' => $plan->id,
                'status' => 'active',
                'is_trial' => false,
                'group_number' => 1,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
                'created_by' => $admin->id,
            ],
        );

        Profile::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'name' => 'Principal',
            ],
            [
                'avatar_url' => null,
                'is_kids' => false,
                'pin_hash' => null,
            ],
        );
    }

    private function adminPassword(): string
    {
        $password = config('pixflix.admin.password');

        if (! is_string($password) || $password === '') {
            throw new \RuntimeException('ADMIN_PASSWORD debe estar configurada para ejecutar el seeder.');
        }

        return $password;
    }
}
