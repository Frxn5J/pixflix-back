<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE_INDEX = 'watch_progress_profile_title_unique';

    /**
     * Episode rows also store the series' title_id, so unique(profile_id,
     * title_id) rejected the second episode of any series. Title-level
     * uniqueness only applies to rows without an episode.
     */
    public function up(): void
    {
        Schema::table('watch_progress', function (Blueprint $table): void {
            $table->dropUnique(['profile_id', 'title_id']);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::TITLE_INDEX
                .' ON watch_progress (profile_id, title_id) WHERE episode_id IS NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX '.self::TITLE_INDEX);
        }

        Schema::table('watch_progress', function (Blueprint $table): void {
            $table->unique(['profile_id', 'title_id']);
        });
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
