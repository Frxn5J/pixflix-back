<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Season;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_local_catalog_seed_creates_a_versioned_snapshot(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertDatabaseHas('catalog_snapshots', [
            'version' => 1,
            'status' => 'success',
            'triggered_by' => 'manual',
        ]);
        $this->assertDatabaseCount('titles', 6);
        $this->assertDatabaseCount('episodes', 8);
        $this->assertSame(6, Title::query()->where('snapshot_version', 1)->count());
    }

    public function test_subscriber_can_filter_and_paginate_the_catalog(): void
    {
        $token = $this->subscriberToken();

        Title::factory()->create([
            'slug' => 'aurora-cero',
            'title' => 'Aurora Cero',
            'type' => 'movie',
            'genres' => ['Ciencia ficción'],
            'languages' => ['Latino'],
            'year' => '2025',
            'category' => 'featured',
        ]);
        Title::factory()->create([
            'slug' => 'reacher',
            'title' => 'Reacher',
            'type' => 'tvshow',
            'genres' => ['Acción'],
            'languages' => ['Latino'],
            'year' => '2022',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog?type=movie&genre=Ciencia%20ficcion&per_page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links' => ['next', 'prev'],
            ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog?type=movie&genre=Ciencia%20ficci%C3%B3n')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'aurora-cero');
    }

    public function test_featured_genres_and_detail_use_the_public_catalog_contract(): void
    {
        $token = $this->subscriberToken();
        $title = Title::factory()->create([
            'slug' => 'reacher',
            'type' => 'tvshow',
            'category' => 'featured',
            'raw_extract' => ['extractUrl' => 'internal-only'],
        ]);
        $season = Season::factory()->create(['title_id' => $title->id]);
        $season->episodes()->create([
            'number' => 1,
            'title' => 'El comienzo',
            'url' => 'https://internal.test/episode',
            'extract_url' => 'https://internal.test/extract',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/featured')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'reacher');

        $this->withToken($token)
            ->getJson('/api/v1/catalog/genres')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($token)
            ->getJson('/api/v1/titles/reacher')
            ->assertOk()
            ->assertJsonPath('data.seasons.0.episodes.0.title', 'El comienzo')
            ->assertJsonMissingPath('data.snapshot_version')
            ->assertJsonMissingPath('data.raw_extract')
            ->assertJsonMissingPath('data.seasons.0.episodes.0.url')
            ->assertJsonMissingPath('data.seasons.0.episodes.0.extract_url');
    }

    public function test_catalog_requires_an_active_subscription(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::factory()->create(['user_id' => $user->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $subscription->update([
            'status' => 'expired',
            'ends_at' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'subscription_inactive');
    }

    private function subscriberToken(): string
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
        ]);

        return $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->json('data.token');
    }
}
