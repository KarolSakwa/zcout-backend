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
        Schema::table('countries', function (Blueprint $table) {
            Schema::table('countries', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('iso2', 2)->nullable()->index();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            Schema::table('countries', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->dropIndex(['iso2']);
                $table->dropColumn('iso2');
            });
        });
    }
};
