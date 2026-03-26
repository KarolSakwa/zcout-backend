<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vote_weight_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_id')->constrained('votes')->cascadeOnDelete();
            $table->unsignedSmallInteger('weight_version');
            $table->unsignedSmallInteger('rating_algorithm_version');
            $table->decimal('base_duel_weight', 8, 4);
            $table->decimal('auth_factor', 8, 4)->default(1);
            $table->decimal('trust_rating_factor', 8, 4)->default(1);
            $table->decimal('trust_confidence_factor', 8, 4)->default(1);
            $table->decimal('integrity_factor', 8, 4)->default(1);
            $table->decimal('bias_factor', 8, 4)->default(1);
            $table->decimal('activity_factor', 8, 4)->default(1);
            $table->decimal('role_factor', 8, 4)->default(1);
            $table->decimal('rating_weight_applied', 10, 4);
            $table->decimal('confidence_weight_applied', 10, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->index('vote_id');
            $table->index(['weight_version', 'rating_algorithm_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_weight_logs');
    }
};
