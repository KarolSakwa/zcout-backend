<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->unsignedInteger('fpl_now_cost')->nullable()->after('minutes_long_term');
        });
    }

    public function down(): void
    {
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->dropColumn('fpl_now_cost');
        });
    }
};
