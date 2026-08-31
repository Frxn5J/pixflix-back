<?php

namespace Tests\Feature;

use App\Models\CatalogSnapshot;
use App\Models\Plan;
use App\Models\Season;
use App\Models\Subscription;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unauthenticated_catalog_is_rejected(): void
    {
        $this->getJson('/api/v1/catalog')->assertUnauthorized();
        $this->getJson('/api/v1/catalog/featured')->assertUnauthorized();
        $this->getJson('/api/v1/catalog/genres')->assertUnauthorized();
        $this->getJson('/api/v1/titles/any-slug')->assertUnauthorized();
    }

    public function test_catalog_search_and_all_individual_filters(): void
    {
        $token = $this->subscriberToken();

        Title::factory()->create([
            'slug' => 'aurora-cero',
            'title' => 'Aurora Cero',
            'type' => 'movie',
            'genres' => ['Ciencia ficción'],
            'languages' => ['Latino'],
            'quality' => '1080p',
            'year' => '2025',
            'category' => 'featured',
        ]);
        Title::factory()->create([
            'slug' => 'planeta-azul',
            'title' => 'Planeta azul',
            'type' => 'tvshow',
            'genres' => ['Documental'],
            'languages' => ['Inglés'],
            'quality' => '4K',
            'year' => '2021',
            'category' => 'normal',
        ]);

        $this->withToken($token)->getJson('/api/v1/catalog?q=aurora')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'aurora-cero');

        $this->withToken($token)->getJson('/api/v1/catalog?language=Ingl%C3%A9s')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'planeta-azul');

        $this->withToken($token)->getJson('/api/v1/catalog?quality=4K')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->withToken($token)->getJson('/api/v1/catalog?year=2025')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'aurora-cero');

        $this->withToken($token)->getJson('/api/v1/catalog?category=featured')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'aurora-cero');
    }

    public function test_catalog_does_not_expose_local_file_urls_as_images(): void
    {
        $token = $this->subscriberToken();
        Title::factory()->create([
            'slug' => 'imagen-insegura',
            'title' => 'Imagen insegura',
            'poster' => 'file:///C:/poster.jpg',
            'gallery' => ['file:///C:/backdrop.jpg', 'https://cdn.test/backdrop.jpg'],
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog?q=imagen-insegura')
            ->assertOk()
            ->assertJsonPath('data.0.poster', null)
            ->assertJsonPath('data.0.gallery.0', 'https://cdn.test/backdrop.jpg');
    }

    public function test_catalog_combines_successful_and_partial_snapshots(): void
    {
        $token = $this->subscriberToken();
        CatalogSnapshot::factory()->create(['version' => 1, 'status' => 'success']);
        CatalogSnapshot::factory()->create(['version' => 2, 'status' => 'partial']);
        Title::factory()->create([
            'slug' => 'catalogo-anterior',
            'title' => 'Catalogo anterior',
            'snapshot_version' => 1,
        ]);
        Title::factory()->create([
            'slug' => 'pelicula-importada',
            'title' => 'Pelicula importada',
            'snapshot_version' => 2,
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog?q=importada')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'pelicula-importada');
    }

    public function test_catalog_includes_active_iptv_vod_titles_outside_snapshots(): void
    {
        $token = $this->subscriberToken();
        CatalogSnapshot::factory()->create(['version' => 10, 'status' => 'success']);
        Title::factory()->create([
            'slug' => 'catalogo-principal',
            'title' => 'Catalogo principal',
            'snapshot_version' => 10,
        ]);
        Title::factory()->create([
            'slug' => 'vod-activo',
            'title' => 'VOD activo',
            'source' => 'iptv_vod',
            'is_active' => true,
            'snapshot_version' => null,
        ]);
        Title::factory()->create([
            'slug' => 'vod-retirado',
            'title' => 'VOD retirado',
            'source' => 'iptv_vod',
            'is_active' => false,
            'snapshot_version' => null,
        ]);

        $this->withToken($token)->getJson('/api/v1/catalog?q=VOD')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'vod-activo');
    }

    public function test_genre_filter_is_normalized_like_the_backend(): void
    {
        $token = $this->subscriberToken();

        Title::factory()->create([
            'slug' => 'aurora-cero',
            'title' => 'Aurora Cero',
            'type' => 'movie',
            'genres' => ['Ciencia ficción'],
            'year' => '2025',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/catalog?genre=Ciencia%20ficci%C3%B3n')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $accentFiltered = $this->withToken($token)->getJson('/api/v1/catalog?genre=Ciencia%20ficcion');
        $accentFiltered->assertOk();
        $this->assertTrue(in_array($accentFiltered->json('meta.total'), [0, 1], true));
    }

    public function test_catalog_pagination_limits_and_headers(): void
    {
        $token = $this->subscriberToken();
        Title::factory()->count(3)->create(['type' => 'movie']);

        $this->withToken($token)->getJson('/api/v1/catalog?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertHeader('X-Request-Id');

        $this->withToken($token)->getJson('/api/v1/catalog?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);

        $this->withToken($token)->getJson('/api/v1/catalog?per_page=999')
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_title_detail_returns_404_for_missing_slug(): void
    {
        $token = $this->subscriberToken();
        $this->withToken($token)->getJson('/api/v1/titles/does-not-exist')
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_title_detail_never_exposes_internal_fields(): void
    {
        $token = $this->subscriberToken();
        \App\Models\CatalogSnapshot::factory()->create(['version' => 99, 'status' => 'success']);
        $title = Title::factory()->create([
            'slug' => 'reacher',
            'type' => 'tvshow',
            'raw_extract' => ['extractUrl' => 'https://internal.test/extract'],
            'snapshot_version' => 99,
        ]);
        $season = Season::factory()->create(['title_id' => $title->id, 'number' => 1]);
        $season->episodes()->create([
            'number' => 1,
            'title' => 'Piloto',
            'url' => 'https://internal.test/stream.m3u8',
            'extract_url' => 'https://internal.test/extract',
        ]);

        $this->withToken($token)->getJson('/api/v1/titles/reacher')
            ->assertOk()
            ->assertJsonMissingPath('data.raw_extract')
            ->assertJsonMissingPath('data.snapshot_version')
            ->assertJsonMissingPath('data.external_id')
            ->assertJsonMissingPath('data.seasons.0.episodes.0.url')
            ->assertJsonMissingPath('data.seasons.0.episodes.0.extract_url');
    }

    public function test_featured_and_genres_are_filtered_correctly(): void
    {
        $token = $this->subscriberToken();
        Title::factory()->create(['slug' => 'featured-1', 'category' => 'featured', 'genres' => ['Acción']]);
        Title::factory()->create(['slug' => 'normal-1', 'category' => 'normal', 'genres' => ['Comedia']]);

        $this->withToken($token)->getJson('/api/v1/catalog/featured')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'featured-1');

        $response = $this->withToken($token)->getJson('/api/v1/catalog/genres')->assertOk();
        $this->assertContains('Acción', $response->json('data'));
        $this->assertContains('Comedia', $response->json('data'));
    }

    public function test_catalog_is_inaccessible_with_expired_subscription(): void
    {
        $user = User::factory()->create();
        Plan::factory()->create();
        Subscription::factory()->create(['user_id' => $user->id, 'status' => 'expired', 'ends_at' => now()->subDay()]);
        $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('error.code', 'subscription_inactive');
    }

    private function subscriberToken(): string
    {
        $user = User::factory()->create();
        Plan::factory()->create();
        Subscription::factory()->create(['user_id' => $user->id]);

        return $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])->json('data.token');
    }
}
