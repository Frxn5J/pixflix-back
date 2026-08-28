<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table): void {
            $table->string('imdb_id')->nullable()->after('external_id')->index();
            $table->json('metadata')->nullable()->after('raw_extract');
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table): void {
            $table->dropIndex(['imdb_id']);
            $table->dropColumn(['imdb_id', 'metadata']);
        });
    }
};
