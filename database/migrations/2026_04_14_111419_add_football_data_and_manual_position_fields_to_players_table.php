<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('fd_position_id')->nullable()->after('position_id')->constrained('positions');
            $table->foreignId('manual_position_id')->nullable()->after('fd_position_id')->constrained('positions');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manual_position_id');
            $table->dropConstrainedForeignId('fd_position_id');
        });
    }
};
