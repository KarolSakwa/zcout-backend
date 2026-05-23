<?php

namespace App\Simulation\Truth;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\SimulationRun;
use App\Models\SimulationRunTruthRating;
use App\Simulation\Contracts\TruthProvider;
use App\Support\Seed;
use Illuminate\Support\Facades\DB;

final class DatabaseSnapshotTruthProvider implements TruthProvider
{
    public function snapshotForRun(SimulationRun $run): void
    {
        $attributes = Attribute::query()
            ->select(['id', 'key'])
            ->orderBy('id')
            ->get();

        $players = Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with([
                'positionRef:id,short_label,key,label,group',
                'fdPositionRef:id,short_label,key,label,group',
                'manualPositionRef:id,short_label,key,label,group',
            ])
            ->orderBy('id')
            ->get();

        $existing = DB::table('player_attribute_ratings as par')
            ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
            ->select([
                'par.player_id',
                'a.key as attribute_key',
                'par.rating',
            ])
            ->get();

        $existingMap = [];

        foreach ($existing as $row) {
            $existingMap[(int) $row->player_id . '|' . (string) $row->attribute_key] = (float) $row->rating;
        }

        $payload = [];

        foreach ($players as $player) {
            $pos = strtoupper((string) ($player->effective_position_short ?? 'CM'));

            foreach ($attributes as $attribute) {
                $mapKey = (int) $player->id . '|' . (string) $attribute->key;

                if (! array_key_exists($mapKey, $existingMap)) {
                    continue;
                }

                $truthRating = $existingMap[$mapKey];

                $payload[] = [
                    'simulation_run_id' => $run->id,
                    'player_id' => (int) $player->id,
                    'attribute_key' => (string) $attribute->key,
                    'truth_rating' => round($truthRating, 2),
                    'source_label' => array_key_exists($mapKey, $existingMap)
                        ? 'database_snapshot_with_existing_rating'
                        : 'database_snapshot_with_seed_fallback',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($payload, 1000) as $chunk) {
            SimulationRunTruthRating::query()->insert($chunk);
        }
    }
}
