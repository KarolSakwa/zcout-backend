<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('fd_name')->nullable()->after('name');
            $table->unsignedSmallInteger('fd_number')->nullable()->after('number');
            $table->timestampTz('fd_synced_at')->nullable()->after('fd_number');

            $table->string('manual_display_name')->nullable()->after('fd_synced_at');
            $table->unsignedSmallInteger('manual_number')->nullable()->after('manual_display_name');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn([
                'fd_name',
                'fd_number',
                'fd_synced_at',
                'manual_display_name',
                'manual_number',
            ]);
        });
    }
};
