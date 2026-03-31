<?php

namespace App\Simulation\Actions;

use Illuminate\Support\Facades\DB;

final class InitializeSimulationStateFromTruthSnapshot
{
    public function handle(int $simulationRunId): void
    {
        $attributeIdsByKey = DB::table('attributes')
            ->pluck('id', 'key')
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::table('simulation_run_truth_ratings')
            ->where('simulation_run_id', $simulationRunId)
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($attributeIdsByKey): void {
                $payload = [];

                foreach ($rows as $row) {
                    $attributeKey = (string) $row->attribute_key;
                    $attributeId = $attributeIdsByKey[$attributeKey] ?? null;

                    if ($attributeId === null) {
                        continue;
                    }

                    $payload[] = [
                        'player_id' => (int) $row->player_id,
                        'attribute_id' => $attributeId,
                        'rating' => round((float) $row->truth_rating, 3),
                        'votes_count' => 0,
                        'rating_weight_sum' => 0,
                        'confidence_weight_sum' => 0,
                        'confidence' => 0,
                        'last_vote_at' => null,
                    ];
                }

                if ($payload === []) {
                    return;
                }

                DB::table('player_attribute_ratings')->upsert(
                    $payload,
                    ['player_id', 'attribute_id'],
                    ['rating', 'votes_count', 'rating_weight_sum', 'confidence_weight_sum', 'confidence', 'last_vote_at']
                );
            });
    }
}
