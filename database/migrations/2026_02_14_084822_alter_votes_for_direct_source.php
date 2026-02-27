<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE votes ALTER COLUMN duel_id DROP NOT NULL");
        DB::statement("ALTER TABLE votes ALTER COLUMN winner_id DROP NOT NULL");

        DB::statement("
            ALTER TABLE votes
            ADD CONSTRAINT votes_source_shape_chk
            CHECK (
                (
                    source = 'duel'
                    AND duel_id IS NOT NULL
                    AND player_a_id IS NOT NULL
                    AND player_b_id IS NOT NULL
                    AND winner_id IS NOT NULL
                    AND attribute_id IS NOT NULL
                    AND value IS NULL
                    AND (winner_id = player_a_id OR winner_id = player_b_id)
                )
                OR
                (
                    source = 'direct'
                    AND user_id IS NOT NULL
                    AND player_a_id IS NOT NULL
                    AND attribute_id IS NOT NULL
                    AND value IS NOT NULL
                    AND duel_id IS NULL
                    AND player_b_id IS NULL
                    AND winner_id IS NULL
                )
            )
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS votes_direct_player_attr_created_idx
            ON votes (player_a_id, attribute_id, created_at)
            WHERE source = 'direct'
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE votes DROP CONSTRAINT IF EXISTS votes_source_shape_chk");
        DB::statement("DROP INDEX IF EXISTS votes_direct_player_attr_created_idx");

        DB::statement("ALTER TABLE votes ALTER COLUMN duel_id SET NOT NULL");
        DB::statement("ALTER TABLE votes ALTER COLUMN winner_id SET NOT NULL");
    }
};
