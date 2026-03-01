<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->string('scope')->default('both');
            $table->index('scope');
        });

        DB::table('attributes')
            ->whereIn('key', [
                'gk_reflexes',
                'gk_one_on_ones',
                'gk_handling',
                'gk_command_of_area',
                'gk_passing',
                'gk_throwing',
                'gk_rushing_out',
                'gk_eccentricity',
            ])
            ->update(['scope' => 'gk']);
    }

    public function down(): void
    {
        Schema::table('attributes', function (Blueprint $table) {
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
        });
    }
};
