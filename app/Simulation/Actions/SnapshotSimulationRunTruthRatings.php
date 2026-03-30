<?php

namespace App\Simulation\Actions;

use App\Models\SimulationRun;
use App\Models\SimulationRunTruthRating;
use Illuminate\Support\Facades\DB;

final class SnapshotSimulationRunTruthRatings
{
    public function handle(SimulationRun $run): void
    {
        DB::table('player_attribute_ratings as par')
            ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
            ->select([
                'par.player_id',
                'a.key as attribute_key',
                'par.rating',
            ])
            ->orderBy('par.id')
            ->chunk(1000, function ($rows) use ($run): void {
                $payload = [];

                foreach ($rows as $row) {
                    if ($row->rating === null) {
                        continue;
                    }

                    $payload[] = [
                        'simulation_run_id' => $run->id,
                        'player_id' => (int) $row->player_id,
                        'attribute_key' => (string) $row->attribute_key,
                        'truth_rating' => round((float) $row->rating, 2),
                        'source_label' => 'current_player_attribute_ratings_snapshot',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($payload !== []) {
                    SimulationRunTruthRating::query()->insert($payload);
                }
            });
    }
}
