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
        Schema::table('votes', function (Blueprint $table) {
            $table->foreignId('duel_id')
                ->after('id')
                ->constrained('duels')
                ->cascadeOnDelete();
        });

        Schema::table('votes', function (Blueprint $table) {
            $table->dropForeign(['attribute_id']);
            $table->dropForeign(['player_a_id']);
            $table->dropForeign(['player_b_id']);

            $table->dropColumn(['attribute_id', 'player_a_id', 'player_b_id']);
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->foreignId('attribute_id')->constrained();
            $table->foreignId('player_a_id')->constrained('players');
            $table->foreignId('player_b_id')->constrained('players');

            $table->dropForeign(['duel_id']);
            $table->dropColumn('duel_id');
        });
    }

};
