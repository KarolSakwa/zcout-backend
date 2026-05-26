<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_overalls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('player_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('position', 10);

            $table->decimal('overall', 5, 2);
            $table->decimal('confidence', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['player_id', 'position']);
            $table->index(['position', 'overall']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_overalls');
    }
};
