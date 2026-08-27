<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id')->nullable()->unique();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('category')->default('general');
            $table->string('country')->nullable();
            $table->string('language')->nullable();
            $table->text('stream_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['category', 'country', 'language']);
        });

        Schema::create('epg_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->timestamps();
            $table->index(['channel_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epg_entries');
        Schema::dropIfExists('channels');
    }
};
