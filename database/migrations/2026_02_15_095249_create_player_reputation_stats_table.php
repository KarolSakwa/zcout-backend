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
        Schema::create('player_reputation_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('player_id')->primary();

            $table->unsignedInteger('minutes_90d')->default(0);
            $table->unsignedInteger('minutes_365d')->default(0);

            $table->decimal('player_rep', 5, 4)->default(0);
            $table->boolean('is_long_tail')->default(false);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->foreign('player_id')->references('id')->on('players')->onDelete('cascade');

            $table->index('player_rep');
            $table->index('is_long_tail');
            $table->index(['is_long_tail', 'player_rep']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_reputation_stats');
    }

};
