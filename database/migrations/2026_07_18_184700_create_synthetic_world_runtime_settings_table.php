<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_world_runtime_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('runtime_enabled')->default(true);
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_mode', 32)->nullable();
            $table->string('updated_source', 64)->nullable();
            $table->timestamp('tick_started_at')->nullable();
            $table->timestamp('tick_finished_at')->nullable();
            $table->timestamp('tick_failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->unsignedInteger('last_tick_duration_ms')->nullable();
            $table->timestamps();
        });

        DB::table('synthetic_world_runtime_settings')->insert([
            'id' => 1,
            'runtime_enabled' => true,
            'paused_at' => null,
            'pause_mode' => null,
            'updated_source' => 'migration',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_world_runtime_settings');
    }
};
