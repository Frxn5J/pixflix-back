<?php

namespace App\Services\Catalog;

use App\Support\UrlSafety;
use Illuminate\Support\Str;

class CatalogNormalizer
{
    public function titleItem(array $item): array
    {
        $url = (string) ($item['url'] ?? '');

        return [
            'external_id' => isset($item['post_id']) ? (string) $item['post_id'] : null,
            'tmdb_id' => $this->tmdbId($item),
            'slug' => $this->slugFromUrl($url, (string) ($item['title'] ?? 'title')),
            'type' => ($item['contentType'] ?? 'movie') === 'tvshow' ? 'tvshow' : 'movie',
            'title' => (string) ($item['title'] ?? 'Sin título'),
            'description' => null,
            'poster' => UrlSafety::http($item['image'] ?? null),
            'gallery' => [],
            'rating' => isset($item['rating']) ? (string) $item['rating'] : null,
            'year' => isset($item['year']) ? (string) $item['year'] : null,
            'quality' => $item['quality'] ?? null,
            'languages' => array_values($item['languages'] ?? []),
            'genres' => [],
            'category' => ($item['category'] ?? 'normal') === 'featured' ? 'featured' : 'normal',
            'total_seasons' => null,
            'total_episodes' => null,
            'raw_extract' => ['url' => $url, 'extractUrl' => $item['extractUrl'] ?? null],
        ];
    }

    public function titleDetail(array $payload): array
    {
        $title = $this->titleItem([
            ...$payload,
            'contentType' => $payload['contentType'] ?? 'movie',
            'image' => $payload['poster'] ?? null,
        ]);

        $title['description'] = $payload['description'] ?? null;
        $title['gallery'] = UrlSafety::httpList($payload['gallery'] ?? []);
        $title['genres'] = array_values($payload['genres'] ?? []);
        $title['total_seasons'] = $payload['totalSeasons'] ?? null;
        $title['total_episodes'] = $payload['totalEpisodes'] ?? null;

        return [
            ...$title,
            'seasons' => array_map(
                fn (array $season): array => [
                    'number' => $season['season'] ?? $season['number'] ?? 0,
                    'title' => $season['title'] ?? 'Temporada',
                    'release_date' => $season['releaseDate'] ?? null,
                    'episodes' => array_map(
                        fn (array $episode): array => [
                            'number' => $episode['episode'] ?? $episode['number'] ?? 0,
                            'title' => $episode['title'] ?? 'Episodio',
                            'url' => $episode['url'] ?? null,
                            'image' => UrlSafety::http($episode['image'] ?? null),
                            'release_date' => $episode['releaseDate'] ?? null,
                            'extract_url' => $episode['extractUrl'] ?? null,
                        ],
                        $season['episodes'] ?? [],
                    ),
                ],
                $payload['seasons'] ?? [],
            ),
        ];
    }

    private function slugFromUrl(string $url, string $fallback): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $segment = is_string($path) ? trim($path, '/') : '';

        return Str::slug($segment !== '' ? basename($segment) : $fallback);
    }

    private function tmdbId(array $item): ?int
    {
        foreach (['tmdb_id', 'tmdbId', 'tmdb'] as $key) {
            $value = $item[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
