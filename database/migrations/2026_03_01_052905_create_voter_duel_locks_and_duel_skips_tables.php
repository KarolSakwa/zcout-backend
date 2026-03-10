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
        Schema::create('voter_duel_locks', function (Blueprint $table) {
            $table->id();
            $table->string('voter_hash', 64)->unique();
            $table->foreignId('duel_id')->constrained('duels')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('duel_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duel_id')->constrained('duels')->cascadeOnDelete();
            $table->string('voter_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['duel_id', 'voter_hash']);
            $table->index(['voter_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duel_skips');
        Schema::dropIfExists('voter_duel_locks');
    }
};
