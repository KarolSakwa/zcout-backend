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
        Schema::table('duels', function (Blueprint $table) {
            $table->smallInteger('pre_rating_a')->nullable();
            $table->smallInteger('pre_rating_b')->nullable();
            $table->smallInteger('post_rating_a')->nullable();
            $table->smallInteger('post_rating_b')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('duels', function (Blueprint $table) {
            //
        });
    }
};
