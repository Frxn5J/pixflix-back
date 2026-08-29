<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('source_playlist_id')->nullable()->after('external_id');
            $table->boolean('use_proxy')->default(true)->after('stream_headers');
            $table->index('source_playlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropIndex(['source_playlist_id']);
            $table->dropColumn(['source_playlist_id', 'use_proxy']);
        });
    }
};
