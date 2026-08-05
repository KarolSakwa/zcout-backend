<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->foreignUuid('scout_report_submission_id')
                ->nullable()
                ->after('user_id')
                ->constrained('scout_report_submissions')
                ->nullOnDelete();

            $table->index('scout_report_submission_id');
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scout_report_submission_id');
        });
    }
};
