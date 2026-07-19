<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision_profile', 32);
            $table->unsignedSmallInteger('sessions_per_day_min');
            $table->unsignedSmallInteger('sessions_per_day_max');
            $table->unsignedSmallInteger('actions_per_session_min');
            $table->unsignedSmallInteger('actions_per_session_max');
            $table->unsignedSmallInteger('delay_seconds_min');
            $table->unsignedSmallInteger('delay_seconds_max');
            $table->decimal('skip_probability', 5, 4);
            $table->decimal('decision_accuracy', 5, 4);
            $table->decimal('noise_level', 5, 4);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_user_profiles');
    }
};
