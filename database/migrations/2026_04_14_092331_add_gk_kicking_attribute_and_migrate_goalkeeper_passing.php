<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $passingId = DB::table('attributes')->where('key', 'passing')->value('id');
            $gkPositionId = DB::table('positions')->where('short_label', 'GK')->value('id');

            if (! $passingId || ! $gkPositionId) {
                return;
            }

            $gkKickingId = DB::table('attributes')->where('key', 'gk_kicking')->value('id');

            if (! $gkKickingId) {
                $gkKickingId = DB::table('attributes')->insertGetId([
                    'key' => 'gk_kicking',
                    'label' => 'Kicking',
                    'group' => 'DISTRIBUTION',
                    'scope' => 'gk',
                ]);
            }

            $gkPlayerIds = DB::table('players')
                ->where('position_id', $gkPositionId)
                ->pluck('id');

            if ($gkPlayerIds->isEmpty()) {
                return;
            }

            DB::table('player_attribute_ratings')
                ->whereIn('player_id', $gkPlayerIds)
                ->where('attribute_id', $passingId)
                ->update([
                    'attribute_id' => $gkKickingId,
                ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $passingId = DB::table('attributes')->where('key', 'passing')->value('id');
            $gkKickingId = DB::table('attributes')->where('key', 'gk_kicking')->value('id');
            $gkPositionId = DB::table('positions')->where('short_label', 'GK')->value('id');

            if (! $passingId || ! $gkKickingId || ! $gkPositionId) {
                return;
            }

            $gkPlayerIds = DB::table('players')
                ->where('position_id', $gkPositionId)
                ->pluck('id');

            if ($gkPlayerIds->isNotEmpty()) {
                DB::table('player_attribute_ratings')
                    ->whereIn('player_id', $gkPlayerIds)
                    ->where('attribute_id', $gkKickingId)
                    ->update([
                        'attribute_id' => $passingId,
                    ]);
            }

            DB::table('attributes')
                ->where('key', 'gk_kicking')
                ->delete();
        });
    }
};
