<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $attributeId = DB::table('attributes')
            ->where('key', 'gk_passing')
            ->value('id');

        if (!$attributeId) {
            return;
        }

        DB::table('votes')
            ->where('attribute_id', $attributeId)
            ->delete();

        DB::table('player_attribute_ratings')
            ->where('attribute_id', $attributeId)
            ->delete();

        DB::table('attributes')
            ->where('id', $attributeId)
            ->delete();
    }

    public function down(): void
    {
        $exists = DB::table('attributes')
            ->where('key', 'gk_passing')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('attributes')->insert([
            'key' => 'gk_passing',
            'label' => 'Passing',
            'group' => 'DISTRIBUTION',
        ]);
    }
};
