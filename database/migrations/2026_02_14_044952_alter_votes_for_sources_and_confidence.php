<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->string('source', 16)->default('duel');
            $table->decimal('weight_applied', 8, 3)->default(1.000);
            $table->smallInteger('weight_version')->default(1);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->smallInteger('reputation_at_vote')->nullable();
            $table->smallInteger('risk_score_at_vote')->nullable();
            $table->smallInteger('value')->nullable();
        });

        DB::statement('ALTER TABLE votes ALTER COLUMN duel_id DROP NOT NULL');

        DB::statement("
            UPDATE votes v
            SET
                attribute_id = d.attribute_id,
                player_a_id  = d.player_a_id,
                player_b_id  = d.player_b_id
            FROM duels d
            WHERE v.duel_id = d.id
              AND (v.attribute_id IS NULL OR v.player_a_id IS NULL OR v.player_b_id IS NULL)
        ");

        DB::statement('ALTER TABLE votes ALTER COLUMN attribute_id SET NOT NULL');

        Schema::table('votes', function (Blueprint $table) {
            $table->index(['player_a_id', 'attribute_id', 'created_at'], 'votes_player_a_attr_created_idx');
            $table->index(['player_b_id', 'attribute_id', 'created_at'], 'votes_player_b_attr_created_idx');
            $table->index(['winner_id', 'attribute_id', 'created_at'], 'votes_winner_attr_created_idx');
            $table->index(['source', 'created_at'], 'votes_source_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropIndex('votes_player_a_attr_created_idx');
            $table->dropIndex('votes_player_b_attr_created_idx');
            $table->dropIndex('votes_winner_attr_created_idx');
            $table->dropIndex('votes_source_created_idx');
        });

        DB::statement('ALTER TABLE votes ALTER COLUMN attribute_id DROP NOT NULL');

        DB::statement('ALTER TABLE votes ALTER COLUMN duel_id SET NOT NULL');

        Schema::table('votes', function (Blueprint $table) {
            $table->dropColumn([
                'source',
                'weight_applied',
                'weight_version',
                'user_id',
                'reputation_at_vote',
                'risk_score_at_vote',
                'value',
            ]);
        });
    }
};
