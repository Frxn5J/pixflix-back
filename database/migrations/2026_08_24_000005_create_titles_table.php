<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status');
            $table->json('stats')->nullable();
            $table->string('triggered_by');
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });

        Schema::create('titles', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('poster')->nullable();
            $table->json('gallery')->nullable();
            $table->string('rating')->nullable();
            $table->string('year')->nullable();
            $table->string('quality')->nullable();
            $table->json('languages')->nullable();
            $table->json('genres')->nullable();
            $table->string('category')->default('normal');
            $table->unsignedInteger('total_seasons')->nullable();
            $table->unsignedInteger('total_episodes')->nullable();
            $table->json('raw_extract')->nullable();
            $table->unsignedInteger('snapshot_version')->nullable();
            $table->timestamps();

            $table->index(['type', 'category']);
            $table->index('year');
            $table->foreign('snapshot_version')
                ->references('version')
                ->on('catalog_snapshots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
        Schema::dropIfExists('catalog_snapshots');
    }
};
