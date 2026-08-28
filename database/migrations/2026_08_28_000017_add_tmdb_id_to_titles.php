<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('titles', function (Blueprint $table): void {
            $table->unsignedBigInteger('tmdb_id')->nullable()->after('external_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('titles', function (Blueprint $table): void {
            $table->dropIndex(['tmdb_id']);
            $table->dropColumn('tmdb_id');
        });
    }
};
