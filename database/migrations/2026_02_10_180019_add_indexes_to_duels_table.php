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
        Schema::table('duels', function (Blueprint $table) {
            $table->index(['attribute_id', 'status'], 'duels_attribute_status_idx');
            $table->index(['status', 'expires_at'], 'duels_status_expires_idx');
            $table->index(['player_a_id', 'player_b_id'], 'duels_players_pair_idx');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('duels', function (Blueprint $table) {
            //
        });
    }
};
