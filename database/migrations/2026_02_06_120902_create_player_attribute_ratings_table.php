<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('player_attribute_ratings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();

            $table->unsignedSmallInteger('rating')->default(50); // 1–99, start na 50
            $table->unsignedInteger('votes_count')->default(0);

            $table->unique(['player_id', 'attribute_id']);
            $table->index(['attribute_id', 'rating']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_attribute_ratings');
    }
};
