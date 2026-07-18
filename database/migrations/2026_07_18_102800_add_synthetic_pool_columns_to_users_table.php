<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('synthetic_pool_key', 64)->nullable()->after('is_synthetic');
            $table->unsignedInteger('synthetic_pool_index')->nullable()->after('synthetic_pool_key');

            $table->index('synthetic_pool_key');
            $table->unique(
                ['synthetic_pool_key', 'synthetic_pool_index'],
                'users_synthetic_pool_membership_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_synthetic_pool_membership_unique');
            $table->dropIndex(['synthetic_pool_key']);
            $table->dropColumn(['synthetic_pool_key', 'synthetic_pool_index']);
        });
    }
};
