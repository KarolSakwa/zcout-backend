<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('players', function ($table) {
            $table->unsignedInteger('fpl_element_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('players', function ($table) {
            $table->dropUnique(['fpl_element_id']);
            $table->dropColumn('fpl_element_id');
        });
    }
};
