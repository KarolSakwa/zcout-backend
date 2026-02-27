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
            $table->unsignedBigInteger('player_a_id')->nullable();
            $table->unsignedBigInteger('player_b_id')->nullable();
            $table->smallInteger('pre_rating_a')->nullable();
            $table->smallInteger('pre_rating_b')->nullable();
            $table->smallInteger('post_rating_a')->nullable();
            $table->smallInteger('post_rating_b')->nullable();
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->index(['attribute_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            //
        });
    }
};
