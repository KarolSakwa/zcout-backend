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
        Schema::create('player_archetypes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')->constrained()->cascadeOnDelete();

            $table->string('language', 5)->default('en');

            $table->string('label');

            $table->string('fingerprint_hash', 64);

            $table->json('fingerprint_payload');

            $table->json('input_snapshot');

            $table->string('prompt_version');

            $table->string('model');

            $table->timestamp('generated_at');

            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['player_id', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_archetypes');
    }
};
