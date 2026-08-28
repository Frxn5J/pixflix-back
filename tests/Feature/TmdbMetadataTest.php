<?php

namespace Tests\Feature;

use App\Services\Catalog\TmdbMetadataClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TmdbMetadataTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_tmdb_search_and_details_are_normalized_for_catalog_titles(): void
    {
        config()->set('pixflix.tmdb.api_key', 'test-key');
        config()->set('pixflix.tmdb.access_token', '');
        config()->set('pixflix.tmdb.base_url', 'https://tmdb.test/3');
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (parse_url($request->url(), PHP_URL_PATH) === '/3/search/movie'
                && ($query['api_key'] ?? null) === 'test-key'
                && ($query['query'] ?? null) === 'Película Profesional'
                && ($query['year'] ?? null) === '2024') {
                return Http::response(['results' => [[
                    'id' => 123456,
                    'title' => 'Película Profesional',
                    'release_date' => '2024-01-01',
                    'popularity' => 10,
                ]]]);
            }

            if (str_contains($request->url(), '/movie/123456')) {
                return Http::response([
                    'id' => 123456,
                    'title' => 'Película Profesional',
                    'overview' => 'Una sinopsis profesional.',
                    'poster_path' => '/poster.jpg',
                    'backdrop_path' => '/backdrop.jpg',
                    'release_date' => '2024-01-01',
                    'vote_average' => 8.1,
                    'vote_count' => 1000,
                    'popularity' => 20.5,
                    'runtime' => 120,
                    'tagline' => 'Una frase memorable.',
                    'genres' => [['name' => 'Drama'], ['name' => 'Thriller']],
                    'spoken_languages' => [['name' => 'Español'], ['name' => 'Inglés']],
                    'external_ids' => ['imdb_id' => 'tt1234567'],
                    'credits' => [
                        'crew' => [
                            ['name' => 'Directora Demo', 'job' => 'Director'],
                            ['name' => 'Autor Demo', 'job' => 'Screenplay'],
                        ],
                        'cast' => [
                            ['name' => 'Actriz Demo'],
                            ['name' => 'Actor Demo'],
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $metadata = app(TmdbMetadataClient::class)->find('Película Profesional', '2024');

        $this->assertSame(123456, $metadata['tmdb_id']);
        $this->assertSame('Una sinopsis profesional.', $metadata['description']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $metadata['poster']);
        $this->assertSame(['Español', 'Inglés'], $metadata['languages']);
        $this->assertSame(['Drama', 'Thriller'], $metadata['genres']);
        $this->assertSame('Directora Demo', $metadata['metadata']['director']);
        $this->assertSame('Actriz Demo, Actor Demo', $metadata['metadata']['actors']);
        $this->assertSame('https://www.themoviedb.org/movie/123456', $metadata['metadata']['tmdb_url']);
    }

    public function test_tmdb_access_token_is_sent_as_bearer_authentication(): void
    {
        config()->set('pixflix.tmdb.api_key', '');
        config()->set('pixflix.tmdb.access_token', 'test-token');
        config()->set('pixflix.tmdb.base_url', 'https://tmdb.test/3');
        Http::fake([
            'https://tmdb.test/*' => Http::response(['results' => []]),
        ]);

        app(TmdbMetadataClient::class)->find('Película Token', '2024');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-token')
                && ! str_contains($request->url(), 'api_key=');
        });
    }
}
