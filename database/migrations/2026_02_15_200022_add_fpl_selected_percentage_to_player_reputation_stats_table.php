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
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->decimal('fpl_selected_by_percent', 5, 2)->nullable()->after('fpl_now_cost');
        });
    }

    public function down(): void
    {
        Schema::table('player_reputation_stats', function (Blueprint $table) {
            $table->dropColumn('fpl_selected_by_percent');
        });
    }
};
