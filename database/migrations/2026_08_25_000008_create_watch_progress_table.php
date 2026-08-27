<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->nullOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position_sec')->default(0);
            $table->unsignedInteger('duration_sec')->default(0);
            $table->float('percent')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamps();

            $table->unique(['profile_id', 'title_id']);
            $table->unique(['profile_id', 'episode_id']);
            $table->index(['profile_id', 'updated_at']);
        });

        Schema::create('playback_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('unknown');
            $table->string('event')->default('play');
            $table->foreignId('title_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained('episodes')->nullOnDelete();
            $table->string('quality')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_logs');
        Schema::dropIfExists('watch_progress');
    }
};
