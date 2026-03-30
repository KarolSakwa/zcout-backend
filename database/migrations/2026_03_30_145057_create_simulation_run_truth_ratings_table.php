<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_run_truth_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('attribute_key');
            $table->decimal('truth_rating', 5, 2);
            $table->string('source_label')->nullable();
            $table->timestamps();

            $table->unique(['simulation_run_id', 'player_id', 'attribute_key'], 'sim_truth_unique');
            $table->index(['simulation_run_id', 'attribute_key'], 'sim_truth_run_attr_idx');
            $table->index(['simulation_run_id', 'player_id'], 'sim_truth_run_player_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_run_truth_ratings');
    }
};
