<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_run_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('event_type');
            $table->string('simulated_user_id');
            $table->boolean('is_logged')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_run_events');
    }
};
