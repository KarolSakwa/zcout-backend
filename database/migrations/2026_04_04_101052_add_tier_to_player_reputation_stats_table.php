<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->string('tier', 1)->nullable()->after('player_rep');
            $table->index(['tier', 'player_rep']);
        });
    }

    public function down(): void
    {
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->dropIndex(['tier', 'player_rep']);
            $table->dropColumn('tier');
        });
    }
};
