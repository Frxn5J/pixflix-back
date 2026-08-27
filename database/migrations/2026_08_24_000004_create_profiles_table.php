<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('avatar_url')->nullable();
            $table->boolean('is_kids')->default(false);
            $table->string('pin_hash')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
