<?php

namespace App\Services\IptvVod;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Services\Catalog\TmdbMetadataClient;
use App\Services\IptvOrg\IptvOrgClient;
use App\Services\SyncSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class IptvVodSyncService
{
    public function __construct(
        private readonly IptvOrgClient $client,
        private readonly SyncSettings $settings,
        private readonly TmdbMetadataClient $tmdb,
    ) {}

    /**
     * @return array{titles: int, movies: int, series: int, episodes: int, deactivated: int}
     */
    public function run(): array
    {
        $movies = [];
        $series = [];

        foreach ($this->configuredPlaylists() as $playlist) {
            if (! ($playlist['enabled'] ?? true)) {
                continue;
            }

            $playlistId = trim((string) ($playlist['id'] ?? 'vod-playlist'));
            $playlistName = trim((string) ($playlist['name'] ?? 'Lista VOD'));
            $language = $this->clean((string) ($playlist['language'] ?? ''));
            $contentType = in_array(($playlist['content_type'] ?? 'auto'), ['auto', 'movie'], true)
                ? (string) ($playlist['content_type'] ?? 'auto')
                : 'auto';

            foreach ($this->client->entries((string) $playlist['url']) as $entry) {
                $entryLanguage = $this->clean((string) ($entry['language'] ?? '')) ?? $language;
                $episodeData = $contentType === 'auto'
                    ? $this->episodeData((string) $entry['name'])
                    : null;

                if ($episodeData === null) {
                    $movies[] = [
                        'playlist_id' => $playlistId,
                        'playlist_name' => $playlistName,
                        'entry' => $entry,
                        'language' => $entryLanguage,
                    ];

                    continue;
                }

                $seriesKey = sha1($playlistId.'|'.Str::lower($episodeData['series']));
                $series[$seriesKey] ??= [
                    'playlist_id' => $playlistId,
                    'playlist_name' => $playlistName,
                    'name' => $episodeData['series'],
                    'poster' => $entry['logo'],
                    'genres' => [],
                    'languages' => [],
                    'episodes' => [],
                ];
                $series[$seriesKey]['poster'] ??= $entry['logo'];
                $series[$seriesKey]['genres'][] = $entry['category'];
                if ($entryLanguage !== null) {
                    $series[$seriesKey]['languages'][] = $entryLanguage;
                }
                $series[$seriesKey]['episodes'][] = [
                    ...$episodeData,
                    'entry' => $entry,
                    'language' => $entryLanguage,
                ];
            }
        }

        if ($movies === [] && $series === []) {
            throw new RuntimeException('Ninguna lista VOD activa devolvio contenido reproducible.');
        }

        $result = DB::transaction(function () use ($movies, $series): array {
            $activeTitleIds = [];
            $activeEpisodeIds = [];

            foreach ($movies as $movie) {
                $entry = $movie['entry'];
                $identity = 'movie|'.$movie['playlist_id'].'|'.$entry['external_id'];
                $metadata = $this->movieMetadata((string) $entry['name']);
                $tmdbMetadata = $this->tmdb->find($metadata['title'], $metadata['year']);
                $metadata = $this->tmdb->apply($metadata, $tmdbMetadata);
                $enriched = $tmdbMetadata === null ? [] : [
                    'tmdb_id' => $tmdbMetadata['tmdb_id'] ?? null,
                    'metadata' => $tmdbMetadata['metadata'] ?? [],
                ];
                $title = Title::query()->updateOrCreate(
                    ['external_id' => 'iptv-vod:'.sha1($identity)],
                    [
                        ...$enriched,
                        'source' => 'iptv_vod',
                        'source_playlist_id' => $movie['playlist_id'],
                        'is_active' => true,
                        'slug' => $this->slug($metadata['title'], $identity),
                        'type' => 'movie',
                        'title' => $metadata['title'],
                        'description' => $metadata['description'] ?? 'Contenido VOD importado desde '.$movie['playlist_name'].'.',
                        'poster' => $metadata['poster'] ?? $entry['logo'],
                        'gallery' => [],
                        'rating' => $metadata['rating'] ?? null,
                        'year' => $metadata['year'],
                        'quality' => $metadata['quality'],
                        'languages' => ($metadata['languages'] ?? []) !== [] ? $metadata['languages'] : $this->listValue($movie['language']),
                        'genres' => array_values(array_unique(array_merge([$entry['category']], (array) ($metadata['genres'] ?? [])))),
                        'category' => 'normal',
                        'total_seasons' => null,
                        'total_episodes' => null,
                        'raw_extract' => ['source' => 'iptv_vod'],
                        'stream_url' => $entry['stream_url'],
                        'stream_headers' => $entry['stream_headers'],
                        'snapshot_version' => null,
                    ],
                );
                $activeTitleIds[] = $title->id;
            }

            foreach ($series as $seriesKey => $seriesData) {
                $identity = 'series|'.$seriesData['playlist_id'].'|'.$seriesKey;
                $seasons = collect($seriesData['episodes'])->groupBy('season');
                $title = Title::query()->updateOrCreate(
                    ['external_id' => 'iptv-vod:'.sha1($identity)],
                    [
                        'source' => 'iptv_vod',
                        'source_playlist_id' => $seriesData['playlist_id'],
                        'is_active' => true,
                        'slug' => $this->slug($seriesData['name'], $identity),
                        'type' => 'tvshow',
                        'title' => $seriesData['name'],
                        'description' => 'Serie VOD importada desde '.$seriesData['playlist_name'].'.',
                        'poster' => $seriesData['poster'],
                        'gallery' => [],
                        'rating' => null,
                        'year' => null,
                        'quality' => $this->seriesQuality($seriesData['episodes']),
                        'languages' => array_values(array_unique($seriesData['languages'])),
                        'genres' => array_values(array_unique(array_filter($seriesData['genres']))),
                        'category' => 'normal',
                        'total_seasons' => $seasons->count(),
                        'total_episodes' => count($seriesData['episodes']),
                        'raw_extract' => ['source' => 'iptv_vod'],
                        'stream_url' => null,
                        'stream_headers' => null,
                        'snapshot_version' => null,
                    ],
                );
                $activeTitleIds[] = $title->id;

                foreach ($seasons as $seasonNumber => $episodes) {
                    $season = Season::query()->updateOrCreate(
                        ['title_id' => $title->id, 'number' => (int) $seasonNumber],
                        [
                            'title' => 'Temporada '.(int) $seasonNumber,
                            'release_date' => null,
                        ],
                    );

                    foreach ($episodes as $episodeData) {
                        $entry = $episodeData['entry'];
                        $episode = Episode::query()->updateOrCreate(
                            ['season_id' => $season->id, 'number' => (int) $episodeData['episode']],
                            [
                                'source' => 'iptv_vod',
                                'source_playlist_id' => $seriesData['playlist_id'],
                                'is_active' => true,
                                'title' => $episodeData['title'],
                                'url' => null,
                                'image' => $entry['logo'],
                                'release_date' => null,
                                'extract_url' => null,
                                'streams' => null,
                                'stream_url' => $entry['stream_url'],
                                'stream_headers' => $entry['stream_headers'],
                            ],
                        );
                        $activeEpisodeIds[] = $episode->id;
                    }
                }
            }

            $deactivatedTitles = Title::query()
                ->where('source', 'iptv_vod')
                ->whereNotIn('id', $activeTitleIds)
                ->update(['is_active' => false, 'updated_at' => now()]);
            $episodeQuery = Episode::query()->where('source', 'iptv_vod');
            if ($activeEpisodeIds !== []) {
                $episodeQuery->whereNotIn('id', $activeEpisodeIds);
            }
            $deactivatedEpisodes = $episodeQuery->update(['is_active' => false, 'updated_at' => now()]);

            return [
                'titles' => count($activeTitleIds),
                'movies' => count($movies),
                'series' => count($series),
                'episodes' => count($activeEpisodeIds),
                'deactivated' => $deactivatedTitles + $deactivatedEpisodes,
            ];
        });

        // Invalidate cached catalog payloads so the fresh VOD titles show up.
        Cache::forever('pixflix:catalog-stamp', (string) now()->unix());

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function configuredPlaylists(): array
    {
        $playlists = $this->settings->get('iptv.vod_playlists', []);

        return is_array($playlists) ? array_values(array_filter(
            $playlists,
            fn (mixed $playlist): bool => is_array($playlist) && ! empty($playlist['url']),
        )) : [];
    }

    /** @return array{series: string, season: int, episode: int, title: string}|null */
    private function episodeData(string $name): ?array
    {
        $patterns = [
            '/^(.*?)\s*[._ -]+S\s*(\d{1,3})\s*[._ -]*E\s*(\d{1,4})(?:\s*[._ -]+(.*))?$/iu',
            '/^(.*?)\s*[._ -]+(\d{1,3})x(\d{1,4})(?:\s*[._ -]+(.*))?$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($name), $matches) !== 1) {
                continue;
            }

            $series = $this->cleanName($matches[1]);
            if ($series === '') {
                return null;
            }

            $episode = (int) $matches[3];

            return [
                'series' => $series,
                'season' => max(1, (int) $matches[2]),
                'episode' => max(1, $episode),
                'title' => $this->cleanName($matches[4] ?? '') ?: 'Episodio '.max(1, $episode),
            ];
        }

        return null;
    }

    /** @return array{title: string, year: ?string, quality: ?string} */
    private function movieMetadata(string $name): array
    {
        $year = preg_match('/(?:^|[\s(\[])(19\d{2}|20\d{2})(?:[\s)\]]|$)/', $name, $yearMatch) === 1
            ? $yearMatch[1]
            : null;
        $quality = preg_match('/\b(4K|2160p|1080p|720p|480p)\b/i', $name, $qualityMatch) === 1
            ? strtoupper($qualityMatch[1])
            : null;
        $title = preg_replace('/\s*[\[(](?:19\d{2}|20\d{2})[\])]\s*/', ' ', $name) ?? $name;
        $title = preg_replace('/\s*[\[(]?(?:4K|2160p|1080p|720p|480p)[\])]?(?:\s|$)/i', ' ', $title) ?? $title;
        $title = $this->cleanName($title);

        return [
            'title' => $title !== '' ? $title : trim($name),
            'year' => $year,
            'quality' => $quality,
        ];
    }

    private function seriesQuality(array $episodes): ?string
    {
        foreach ($episodes as $episode) {
            $quality = $this->movieMetadata((string) $episode['entry']['name'])['quality'];
            if ($quality !== null) {
                return $quality;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function listValue(?string $value): array
    {
        return $value === null ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : strtolower($value);
    }

    private function cleanName(string $value): string
    {
        return trim((string) preg_replace('/[._]+/', ' ', trim($value)), " \t\n\r\0\x0B-");
    }

    private function slug(string $name, string $identity): string
    {
        return 'vod-'.(Str::slug($name) ?: 'contenido').'-'.substr(sha1($identity), 0, 10);
    }
}
