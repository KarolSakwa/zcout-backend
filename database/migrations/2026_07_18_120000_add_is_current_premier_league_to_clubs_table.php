<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compatibility rollout:
     * - existing clubs are backfilled to true (preserve pre-sync app behaviour)
     * - database default for future inserts without an explicit value is false
     * - season sync then sets exactly the current Premier League set to true
     */
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            // Temporary default true so PostgreSQL fills existing rows on ADD COLUMN.
            $table->boolean('is_current_premier_league')->default(true);
            $table->index('is_current_premier_league');
        });

        // Explicit backfill: works for 0, 20, or any historical club count.
        DB::table('clubs')->update([
            'is_current_premier_league' => true,
        ]);

        // Future rows without an explicit value must not become active PL clubs.
        DB::statement('ALTER TABLE clubs ALTER COLUMN is_current_premier_league SET DEFAULT false');
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropIndex(['is_current_premier_league']);
            $table->dropColumn('is_current_premier_league');
        });
    }
};
