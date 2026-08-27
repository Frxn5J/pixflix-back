<?php

namespace Database\Seeders;

use App\Models\Channel;
use Illuminate\Database\Seeder;

class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channel = Channel::query()->updateOrCreate(
            ['external_id' => 'pixflix-demo-news'],
            [
                'name' => 'Pixflix Noticias',
                'category' => 'Noticias',
                'country' => 'MX',
                'language' => 'Español',
                'logo' => 'https://placehold.co/160x90/1d4b50/f4f4f5?text=Noticias',
                'stream_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'is_active' => true,
            ],
        );

        $channel->epgEntries()->updateOrCreate(
            ['start_at' => now()->startOfHour()],
            [
                'title' => 'Noticias de hoy',
                'description' => 'Resumen de actualidad.',
                'end_at' => now()->startOfHour()->addHour(),
            ],
        );
        $channel->epgEntries()->updateOrCreate(
            ['start_at' => now()->startOfHour()->addHour()],
            [
                'title' => 'Agenda Pixflix',
                'description' => 'Programación destacada.',
                'end_at' => now()->startOfHour()->addHours(2),
            ],
        );
    }
}
