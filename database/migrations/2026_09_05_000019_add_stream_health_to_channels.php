<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->timestamp('stream_checked_at')->nullable()->after('is_active');
            $table->string('stream_check_status', 24)->nullable()->after('stream_checked_at');
            $table->string('stream_check_error', 120)->nullable()->after('stream_check_status');
            $table->index(['stream_check_status', 'stream_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropIndex(['stream_check_status', 'stream_checked_at']);
            $table->dropColumn([
                'stream_checked_at',
                'stream_check_status',
                'stream_check_error',
            ]);
        });
    }
};
