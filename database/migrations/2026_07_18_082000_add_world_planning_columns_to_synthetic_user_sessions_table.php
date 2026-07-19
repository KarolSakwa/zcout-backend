<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('synthetic_user_sessions', function (Blueprint $table) {
            $table->date('activity_date')->nullable()->after('last_action_reason');
            $table->unsignedSmallInteger('daily_session_index')->nullable()->after('activity_date');
            $table->timestamp('scheduled_start_at')->nullable()->after('daily_session_index');

            $table->unique(
                ['user_id', 'activity_date', 'daily_session_index'],
                'synthetic_user_sessions_daily_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('synthetic_user_sessions', function (Blueprint $table) {
            $table->dropUnique('synthetic_user_sessions_daily_unique');
            $table->dropColumn([
                'activity_date',
                'daily_session_index',
                'scheduled_start_at',
            ]);
        });
    }
};
