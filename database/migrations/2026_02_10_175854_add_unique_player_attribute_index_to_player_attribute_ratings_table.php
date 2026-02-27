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
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->unique(['player_id', 'attribute_id'], 'par_player_attribute_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            //
        });
    }
};
