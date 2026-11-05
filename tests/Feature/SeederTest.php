<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_production_seed_only_creates_the_administrator(): void
    {
        config()->set('pixflix.admin.password', 'una-clave-larga-de-admin-1');
        config()->set('pixflix.admin.email', 'owner@example.test');
        $this->app->detectEnvironment(fn () => 'production');

        app(DatabaseSeeder::class)->run();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin']);
        $this->assertDatabaseMissing('users', ['email' => 'agent@pixflix.test']);
        $this->assertDatabaseMissing('users', ['email' => 'subscriber@pixflix.test']);
        $this->assertSame(1, User::query()->where('email', 'owner@example.test')->count());
    }

    public function test_local_seed_creates_demo_accounts(): void
    {
        config()->set('pixflix.admin.password', 'una-clave-larga-de-admin-1');
        $this->app->detectEnvironment(fn () => 'local');

        app(DatabaseSeeder::class)->run();

        $this->assertDatabaseHas('users', ['email' => 'agent@pixflix.test', 'role' => 'agent']);
        $this->assertDatabaseHas('users', ['email' => 'subscriber@pixflix.test']);
    }
}
