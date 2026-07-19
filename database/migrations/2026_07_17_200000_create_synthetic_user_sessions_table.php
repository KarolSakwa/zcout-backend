<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedSmallInteger('planned_actions');
            $table->unsignedSmallInteger('completed_actions')->default(0);
            $table->timestamp('next_action_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('session_seed');
            $table->string('last_action_status', 16)->nullable();
            $table->string('last_action_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_action_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_user_sessions');
    }
};
