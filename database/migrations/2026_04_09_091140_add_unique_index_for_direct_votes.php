<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_direct_user_player_attr_unique
            ON votes (user_id, player_a_id, attribute_id)
            WHERE source = 'direct'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS votes_direct_user_player_attr_unique');
    }
};
