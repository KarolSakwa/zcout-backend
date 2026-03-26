<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->renameColumn('weight_sum', 'rating_weight_sum');
        });

        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->decimal('confidence_weight_sum', 10, 4)->default(0)->after('rating_weight_sum');
        });
    }

    public function down(): void
    {
        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->dropColumn('confidence_weight_sum');
        });

        Schema::table('player_attribute_ratings', function (Blueprint $table) {
            $table->renameColumn('rating_weight_sum', 'weight_sum');
        });
    }
};
