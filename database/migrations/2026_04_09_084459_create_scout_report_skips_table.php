<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scout_report_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->timestamp('skipped_at');
            $table->timestamps();

            $table->unique(['user_id', 'player_id', 'attribute_id'], 'sr_skips_user_player_attr_unique');
            $table->index(['player_id', 'attribute_id'], 'sr_skips_player_attr_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scout_report_skips');
    }
};
