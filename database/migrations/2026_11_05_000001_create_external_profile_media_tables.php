<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('content_id', 191);
            $table->json('content');
            $table->timestamps();
            $table->unique(['profile_id', 'provider', 'content_id']);
        });

        Schema::create('external_watch_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('content_id', 191);
            $table->string('episode_id', 191)->default('');
            $table->json('content');
            $table->unsignedInteger('position_sec');
            $table->unsignedInteger('duration_sec');
            $table->decimal('percent', 5, 2);
            $table->boolean('completed')->default(false);
            $table->timestamps();
            $table->unique(['profile_id', 'provider', 'content_id', 'episode_id'], 'external_progress_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_watch_progress');
        Schema::dropIfExists('external_favorites');
    }
};
