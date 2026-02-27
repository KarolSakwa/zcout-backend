<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->decimal('pre_rating_a', 8, 3)->nullable()->change();
            $table->decimal('post_rating_a', 8, 3)->nullable()->change();
            $table->decimal('pre_rating_b', 8, 3)->nullable()->change();
            $table->decimal('post_rating_b', 8, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->smallInteger('pre_rating_a')->nullable()->change();
            $table->smallInteger('post_rating_a')->nullable()->change();
            $table->smallInteger('pre_rating_b')->nullable()->change();
            $table->smallInteger('post_rating_b')->nullable()->change();
        });
    }
};
