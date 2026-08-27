<?php

namespace Database\Seeders;

use App\Models\CatalogSnapshot;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $snapshot = CatalogSnapshot::query()->updateOrCreate(
            ['version' => 1],
            [
                'started_at' => now(),
                'finished_at' => now(),
                'status' => 'success',
                'stats' => ['movies' => 3, 'tvshows' => 3, 'episodes' => 8, 'errors' => 0],
                'triggered_by' => 'manual',
            ],
        );

        $titles = [
            [
                'external_id' => 'demo-aurora-cero',
                'slug' => 'aurora-cero',
                'type' => 'movie',
                'title' => 'Aurora Cero',
                'description' => 'Una misión científica descubre una señal que puede cambiar el futuro de la Tierra.',
                'poster' => 'https://placehold.co/600x900/17141f/f4f4f5?text=Aurora%20Cero',
                'gallery' => [],
                'rating' => '8.4',
                'year' => '2025',
                'quality' => '1080p',
                'languages' => ['Latino', 'Inglés'],
                'genres' => ['Ciencia ficción', 'Drama'],
                'category' => 'featured',
                'total_seasons' => null,
                'total_episodes' => null,
            ],
            [
                'external_id' => 'demo-reacher',
                'slug' => 'reacher',
                'type' => 'tvshow',
                'title' => 'Reacher',
                'description' => 'Un investigador solitario sigue pistas que conectan una red de crímenes con su pasado.',
                'poster' => 'https://placehold.co/600x900/283044/f4f4f5?text=Reacher',
                'gallery' => [],
                'rating' => '8.1',
                'year' => '2022',
                'quality' => '1080p',
                'languages' => ['Latino', 'Inglés'],
                'genres' => ['Acción', 'Drama', 'Crimen'],
                'category' => 'featured',
                'total_seasons' => 2,
                'total_episodes' => 4,
            ],
            [
                'external_id' => 'demo-ultimo-verano',
                'slug' => 'el-ultimo-verano',
                'type' => 'movie',
                'title' => 'El último verano',
                'description' => 'Tres amigos regresan al pueblo donde crecieron para cerrar una historia pendiente.',
                'poster' => 'https://placehold.co/600x900/493348/f4f4f5?text=El%20ultimo%20verano',
                'gallery' => [],
                'rating' => '7.5',
                'year' => '2024',
                'quality' => '720p',
                'languages' => ['Latino'],
                'genres' => ['Comedia', 'Romance'],
                'category' => 'normal',
                'total_seasons' => null,
                'total_episodes' => null,
            ],
            [
                'external_id' => 'demo-horizonte-rojo',
                'slug' => 'horizonte-rojo',
                'type' => 'movie',
                'title' => 'Horizonte rojo',
                'description' => 'Una piloto debe cruzar un territorio aislado para rescatar a su equipo antes del amanecer.',
                'poster' => 'https://placehold.co/600x900/5a2f2f/f4f4f5?text=Horizonte%20rojo',
                'gallery' => [],
                'rating' => '7.9',
                'year' => '2023',
                'quality' => '1080p',
                'languages' => ['Latino', 'Inglés'],
                'genres' => ['Acción', 'Suspenso'],
                'category' => 'normal',
                'total_seasons' => null,
                'total_episodes' => null,
            ],
            [
                'external_id' => 'demo-planeta-azul',
                'slug' => 'planeta-azul',
                'type' => 'tvshow',
                'title' => 'Planeta azul',
                'description' => 'Un recorrido visual por los ecosistemas más sorprendentes del planeta.',
                'poster' => 'https://placehold.co/600x900/1d4b50/f4f4f5?text=Planeta%20azul',
                'gallery' => [],
                'rating' => '9.0',
                'year' => '2021',
                'quality' => '4K',
                'languages' => ['Latino'],
                'genres' => ['Documental', 'Naturaleza'],
                'category' => 'normal',
                'total_seasons' => 1,
                'total_episodes' => 2,
            ],
            [
                'external_id' => 'demo-misterios-puerto',
                'slug' => 'misterios-del-puerto',
                'type' => 'tvshow',
                'title' => 'Misterios del puerto',
                'description' => 'Una periodista y un detective investigan desapariciones en una ciudad costera.',
                'poster' => 'https://placehold.co/600x900/26343d/f4f4f5?text=Misterios%20del%20puerto',
                'gallery' => [],
                'rating' => '7.8',
                'year' => '2020',
                'quality' => '1080p',
                'languages' => ['Latino'],
                'genres' => ['Misterio', 'Crimen'],
                'category' => 'normal',
                'total_seasons' => 1,
                'total_episodes' => 2,
            ],
        ];

        foreach ($titles as $attributes) {
            $title = Title::query()->updateOrCreate(
                ['slug' => $attributes['slug']],
                [...$attributes, 'snapshot_version' => $snapshot->version],
            );

            if ($title->type !== 'tvshow') {
                continue;
            }

            $seasonCount = $attributes['total_seasons'] ?? 1;
            $episodeCount = $attributes['total_episodes'] ?? 1;
            $episodesPerSeason = max(1, (int) ceil($episodeCount / $seasonCount));

            for ($seasonNumber = 1; $seasonNumber <= $seasonCount; $seasonNumber++) {
                $season = Season::query()->updateOrCreate(
                    ['title_id' => $title->id, 'number' => $seasonNumber],
                    [
                        'title' => "Temporada {$seasonNumber}",
                        'release_date' => $attributes['year'],
                    ],
                );

                for ($episodeNumber = 1; $episodeNumber <= $episodesPerSeason; $episodeNumber++) {
                    $number = (($seasonNumber - 1) * $episodesPerSeason) + $episodeNumber;

                    if ($number > $episodeCount) {
                        break;
                    }

                    Episode::query()->updateOrCreate(
                        ['season_id' => $season->id, 'number' => $episodeNumber],
                        [
                            'title' => "Episodio {$number}",
                            'image' => $title->poster,
                            'release_date' => $attributes['year'],
                        ],
                    );
                }
            }
        }
    }
}
