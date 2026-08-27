<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_snapshots', function (Blueprint $table): void {
            $table->json('checkpoint')->nullable()->after('stats');
            $table->text('error')->nullable()->after('checkpoint');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['checkpoint', 'error']);
        });
    }
};
