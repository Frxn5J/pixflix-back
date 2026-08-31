<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TmdbMetadataClient
{
    private const CACHE_TTL = 604800;

    /** @return array<string, mixed>|null */
    public function find(string $title, ?string $year = null, ?int $tmdbId = null): ?array
    {
        $title = trim($title);
        if ($title === '' && $tmdbId === null) {
            return null;
        }

        if (! $this->configured()) {
            return null;
        }

        $cacheKey = 'pixflix:tmdb:'.sha1(($tmdbId ?? $title).'|'.(string) $year);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($title, $year, $tmdbId): ?array {
            try {
                $movieId = $tmdbId ?? $this->searchMovieId($title, $year);
                if ($movieId === null) {
                    return null;
                }

                return $this->details($movieId);
            } catch (Throwable $error) {
                Log::warning('TMDB metadata request failed', [
                    'title' => $title,
                    'error' => $error->getMessage(),
                ]);

                return null;
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function findSeries(string $title, ?string $year = null, ?string $imdbId = null): ?array
    {
        $title = trim($title);
        $imdbId = trim((string) $imdbId);
        if ($title === '' && $imdbId === '') {
            return null;
        }

        if (! $this->configured()) {
            return null;
        }

        $cacheKey = 'pixflix:tmdb:series:'.sha1(($imdbId !== '' ? $imdbId : $title).'|'.(string) $year);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($title, $year, $imdbId): ?array {
            try {
                $seriesId = $imdbId !== '' ? $this->searchSeriesByImdbId($imdbId) : null;
                $seriesId ??= $this->searchSeriesId($title, $year);

                return $seriesId === null ? null : $this->seriesDetails($seriesId);
            } catch (Throwable $error) {
                Log::warning('TMDB series metadata request failed', [
                    'title' => $title,
                    'imdb_id' => $imdbId !== '' ? $imdbId : null,
                    'error' => $error->getMessage(),
                ]);

                return null;
            }
        });
    }

    /** @param array<string, mixed> $attributes */
    /** @return array<string, mixed> */
    public function apply(array $attributes, ?array $metadata): array
    {
        if ($metadata === null) {
            return $attributes;
        }

        foreach (['title', 'description', 'poster', 'rating', 'year', 'tmdb_id'] as $field) {
            if ($this->present($metadata[$field] ?? null)) {
                $attributes[$field] = $metadata[$field];
            }
        }

        foreach (['languages', 'genres'] as $field) {
            $values = array_values(array_filter(array_map('trim', (array) ($metadata[$field] ?? []))));
            if ($values !== []) {
                $attributes[$field] = array_values(array_unique(array_merge(
                    array_map('strval', (array) ($attributes[$field] ?? [])),
                    $values,
                )));
            }
        }

        $attributes['metadata'] = array_filter([
            ...((array) ($attributes['metadata'] ?? [])),
            ...((array) ($metadata['metadata'] ?? [])),
        ], fn (mixed $value): bool => $this->present($value));

        if (! empty($metadata['backdrop'])) {
            $attributes['gallery'] = array_values(array_unique(array_merge(
                (array) ($attributes['gallery'] ?? []),
                [$metadata['backdrop']],
            )));
        }

        return $attributes;
    }

    private function configured(): bool
    {
        return trim((string) config('pixflix.tmdb.api_key', '')) !== ''
            || trim((string) config('pixflix.tmdb.access_token', '')) !== '';
    }

    private function searchMovieId(string $title, ?string $year): ?int
    {
        $query = [
            ...$this->authQuery(),
            'query' => $title,
            'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
            'include_adult' => 'false',
            'page' => 1,
        ];
        if ($year !== null && preg_match('/^\d{4}$/', $year) === 1) {
            $query['year'] = $year;
            $query['primary_release_year'] = $year;
        }

        $response = $this->request()->get($this->endpoint('/search/movie'), $query);
        if (! $response->successful()) {
            return null;
        }

        $results = array_values(array_filter($response->json('results', []), 'is_array'));
        if ($results === []) {
            return null;
        }

        usort($results, function (array $left, array $right) use ($title, $year): int {
            return $this->movieScore($right, $title, $year) <=> $this->movieScore($left, $title, $year);
        });

        $id = $results[0]['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function searchSeriesByImdbId(string $imdbId): ?int
    {
        $response = $this->request()->get($this->endpoint('/find/'.rawurlencode($imdbId)), [
            ...$this->authQuery(),
            'external_source' => 'imdb_id',
            'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
        ]);
        if (! $response->successful()) {
            return null;
        }

        $results = array_values(array_filter($response->json('tv_results', []), 'is_array'));
        $id = $results[0]['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function searchSeriesId(string $title, ?string $year): ?int
    {
        $query = [
            ...$this->authQuery(),
            'query' => $title,
            'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
            'include_adult' => 'false',
            'page' => 1,
        ];
        if ($year !== null && preg_match('/^\d{4}$/', $year) === 1) {
            $query['first_air_date_year'] = $year;
        }

        $response = $this->request()->get($this->endpoint('/search/tv'), $query);
        if (! $response->successful()) {
            return null;
        }

        $results = array_values(array_filter($response->json('results', []), 'is_array'));
        if ($results === []) {
            return null;
        }

        usort($results, function (array $left, array $right) use ($title, $year): int {
            return $this->seriesScore($right, $title, $year) <=> $this->seriesScore($left, $title, $year);
        });

        $id = $results[0]['id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed>|null */
    private function seriesDetails(int $seriesId): ?array
    {
        $response = $this->request()->get($this->endpoint('/tv/'.$seriesId), [
            ...$this->authQuery(),
            'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
            'append_to_response' => 'external_ids',
        ]);
        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['id'])) {
            return null;
        }

        $videos = [];
        foreach ((array) ($payload['seasons'] ?? []) as $season) {
            if (! is_array($season)) {
                continue;
            }

            $seasonNumber = (int) ($season['season_number'] ?? 0);
            if ($seasonNumber < 1) {
                continue;
            }

            $seasonResponse = $this->request()->get($this->endpoint('/tv/'.$seriesId.'/season/'.$seasonNumber), [
                ...$this->authQuery(),
                'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
            ]);
            if (! $seasonResponse->successful()) {
                continue;
            }

            foreach ((array) $seasonResponse->json('episodes', []) as $episode) {
                if (! is_array($episode)) {
                    continue;
                }

                $episodeNumber = (int) ($episode['episode_number'] ?? 0);
                if ($episodeNumber < 1) {
                    continue;
                }

                $videos[] = [
                    'season' => $seasonNumber,
                    'episode' => $episodeNumber,
                    'title' => $this->usable($episode['name'] ?? null) ?? 'Episodio '.$episodeNumber,
                    'image' => $this->imageUrl($episode['still_path'] ?? null, 'w300'),
                    'release_date' => $this->usable($episode['air_date'] ?? null),
                ];
            }
        }

        if ($videos === []) {
            return null;
        }

        $firstAirDate = (string) ($payload['first_air_date'] ?? '');
        $tmdbId = (int) $payload['id'];
        $episodeRuntime = is_array($payload['episode_run_time'] ?? null)
            ? ($payload['episode_run_time'][0] ?? null)
            : null;
        $runtime = is_numeric($episodeRuntime) ? ((int) $episodeRuntime).' min' : null;

        return [
            'title' => $this->usable($payload['name'] ?? $payload['original_name'] ?? null),
            'description' => $this->usable($payload['overview'] ?? null),
            'poster' => $this->imageUrl($payload['poster_path'] ?? null, 'w500'),
            'backdrop' => $this->imageUrl($payload['backdrop_path'] ?? null, 'w1280'),
            'rating' => isset($payload['vote_average']) ? (string) round((float) $payload['vote_average'], 1) : null,
            'year' => preg_match('/^(\d{4})/', $firstAirDate, $yearMatch) === 1 ? $yearMatch[1] : null,
            'tmdb_id' => $tmdbId,
            'languages' => [],
            'genres' => array_values(array_filter(array_map(
                fn (mixed $genre): string => is_array($genre) ? trim((string) ($genre['name'] ?? '')) : '',
                (array) ($payload['genres'] ?? []),
            ))),
            'metadata' => [
                'tmdb_url' => 'https://www.themoviedb.org/tv/'.$tmdbId,
                'imdb_id' => $payload['external_ids']['imdb_id'] ?? null,
                'status' => $payload['status'] ?? null,
                'released' => $firstAirDate !== '' ? $firstAirDate : null,
                'runtime' => $runtime,
            ],
            'videos' => $videos,
        ];
    }

    /** @return array<string, mixed>|null */
    private function details(int $movieId): ?array
    {
        $response = $this->request()->get($this->endpoint('/movie/'.$movieId), [
            ...$this->authQuery(),
            'language' => (string) config('pixflix.tmdb.language', 'es-MX'),
            'append_to_response' => 'credits,external_ids',
        ]);
        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['id'])) {
            return null;
        }

        $releaseDate = (string) ($payload['release_date'] ?? '');
        $credits = is_array($payload['credits'] ?? null) ? $payload['credits'] : [];
        $crew = array_values(array_filter($credits['crew'] ?? [], 'is_array'));
        $cast = array_values(array_filter($credits['cast'] ?? [], 'is_array'));
        $directors = array_values(array_filter($crew, fn (array $person): bool => ($person['job'] ?? null) === 'Director'));
        $writers = array_values(array_filter($crew, fn (array $person): bool => in_array($person['job'] ?? null, ['Screenplay', 'Writer', 'Story'], true)));
        $backdrop = $this->imageUrl($payload['backdrop_path'] ?? null, 'w1280');
        $tmdbId = (int) $payload['id'];

        return [
            'title' => $this->usable($payload['title'] ?? $payload['original_title'] ?? null),
            'description' => $this->usable($payload['overview'] ?? null),
            'poster' => $this->imageUrl($payload['poster_path'] ?? null, 'w500'),
            'backdrop' => $backdrop,
            'rating' => isset($payload['vote_average']) ? (string) round((float) $payload['vote_average'], 1) : null,
            'year' => preg_match('/^(\d{4})/', $releaseDate, $yearMatch) === 1 ? $yearMatch[1] : null,
            'tmdb_id' => $tmdbId,
            'languages' => array_values(array_filter(array_map(
                fn (mixed $language): string => is_array($language) ? trim((string) ($language['name'] ?? $language['english_name'] ?? '')) : '',
                (array) ($payload['spoken_languages'] ?? []),
            ))),
            'genres' => array_values(array_filter(array_map(
                fn (mixed $genre): string => is_array($genre) ? trim((string) ($genre['name'] ?? '')) : '',
                (array) ($payload['genres'] ?? []),
            ))),
            'metadata' => [
                'tmdb_url' => 'https://www.themoviedb.org/movie/'.$tmdbId,
                'imdb_id' => $payload['external_ids']['imdb_id'] ?? null,
                'tagline' => $payload['tagline'] ?? null,
                'status' => $payload['status'] ?? null,
                'released' => $releaseDate !== '' ? $releaseDate : null,
                'runtime' => isset($payload['runtime']) ? $payload['runtime'].' min' : null,
                'director' => $this->people($directors),
                'writer' => $this->people($writers),
                'actors' => $this->people(array_slice($cast, 0, 5)),
                'vote_count' => $payload['vote_count'] ?? null,
                'popularity' => $payload['popularity'] ?? null,
                'production_companies' => $this->companies($payload['production_companies'] ?? []),
            ],
        ];
    }

    private function request(): mixed
    {
        $request = Http::withOptions([
            'verify' => (bool) config('pixflix.tmdb.verify_ssl', true),
        ])->timeout((int) config('pixflix.tmdb.timeout_seconds', 8))->acceptJson();
        $token = $this->accessToken();

        return $token === '' ? $request : $request->withToken($token);
    }

    /** @return array<string, string> */
    private function authQuery(): array
    {
        $apiKey = trim((string) config('pixflix.tmdb.api_key', ''));

        return $apiKey === '' || $this->accessToken() !== ''
            ? []
            : ['api_key' => $apiKey];
    }

    private function accessToken(): string
    {
        $accessToken = trim((string) config('pixflix.tmdb.access_token', ''));
        if ($accessToken !== '') {
            return $accessToken;
        }

        // TMDB v4 tokens are JWTs. Accepting one in the legacy API-key
        // setting keeps existing installations working while using the
        // correct Bearer authentication scheme.
        $apiKey = trim((string) config('pixflix.tmdb.api_key', ''));

        return preg_match('/^[^.]+\.[^.]+\.[^.]+$/', $apiKey) === 1 ? $apiKey : '';
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('pixflix.tmdb.base_url', 'https://api.themoviedb.org/3'), '/').'/'.ltrim($path, '/');
    }

    private function movieScore(array $movie, string $title, ?string $year): float
    {
        $score = (float) ($movie['popularity'] ?? 0) / 1000;
        if ($this->sameTitle($movie['title'] ?? $movie['original_title'] ?? '', $title)) {
            $score += 100;
        }
        if ($year !== null && str_starts_with((string) ($movie['release_date'] ?? ''), $year)) {
            $score += 50;
        }

        return $score;
    }

    private function seriesScore(array $series, string $title, ?string $year): float
    {
        $score = (float) ($series['popularity'] ?? 0) / 1000;
        if ($this->sameTitle($series['name'] ?? $series['original_name'] ?? '', $title)) {
            $score += 100;
        }
        if ($year !== null && str_starts_with((string) ($series['first_air_date'] ?? ''), $year)) {
            $score += 50;
        }

        return $score;
    }

    private function sameTitle(mixed $left, string $right): bool
    {
        return $this->titleKey((string) $left) === $this->titleKey($right);
    }

    private function titleKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii(trim($value)))) ?? '';
    }

    private function imageUrl(mixed $path, string $size): ?string
    {
        $path = trim((string) $path);

        return $path === '' ? null : 'https://image.tmdb.org/t/p/'.$size.'/'.ltrim($path, '/');
    }

    private function people(array $people): ?string
    {
        $names = array_values(array_filter(array_map(
            fn (array $person): string => trim((string) ($person['name'] ?? '')),
            $people,
        )));

        return $names === [] ? null : implode(', ', $names);
    }

    private function companies(mixed $companies): ?string
    {
        $names = array_values(array_filter(array_map(
            fn (mixed $company): string => is_array($company) ? trim((string) ($company['name'] ?? '')) : '',
            (array) $companies,
        )));

        return $names === [] ? null : implode(', ', $names);
    }

    private function usable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
