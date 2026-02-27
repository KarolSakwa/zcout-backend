<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->decimal('weight_sum', 12, 3)->default(0)->after('votes_count');
            $table->decimal('confidence', 5, 2)->default(0)->after('weight_sum');
            $table->timestamp('last_vote_at')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->dropColumn(['weight_sum', 'confidence', 'last_vote_at']);
        });
    }
};
