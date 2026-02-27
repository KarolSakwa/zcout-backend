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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('label', 255);
            $table->string('short_label', 10)->nullable();
            $table->string('group', 10)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['group', 'order']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->constrained('positions');
            $table->index('position_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('positions');
    }
};
