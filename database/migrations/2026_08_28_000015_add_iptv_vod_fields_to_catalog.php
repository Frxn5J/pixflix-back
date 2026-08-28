<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table): void {
            $table->string('source')->default('catalog')->after('external_id')->index();
            $table->string('source_playlist_id')->nullable()->after('source')->index();
            $table->boolean('is_active')->default(true)->after('source_playlist_id')->index();
            $table->text('stream_url')->nullable()->after('raw_extract');
            $table->json('stream_headers')->nullable()->after('stream_url');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->string('source')->default('catalog')->after('season_id')->index();
            $table->string('source_playlist_id')->nullable()->after('source')->index();
            $table->boolean('is_active')->default(true)->after('source_playlist_id')->index();
            $table->text('stream_url')->nullable()->after('streams');
            $table->json('stream_headers')->nullable()->after('stream_url');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropIndex(['source_playlist_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'source',
                'source_playlist_id',
                'is_active',
                'stream_url',
                'stream_headers',
            ]);
        });

        Schema::table('titles', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropIndex(['source_playlist_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'source',
                'source_playlist_id',
                'is_active',
                'stream_url',
                'stream_headers',
            ]);
        });
    }
};
