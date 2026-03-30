<?php

namespace App\Simulation\Services;

use App\Models\SimulationRunTruthRating;

final class SimulationTruthReader
{
    public function getRating(int $runId, int $playerId, string $attributeKey): ?float
    {
        $value = SimulationRunTruthRating::query()
            ->where('simulation_run_id', $runId)
            ->where('player_id', $playerId)
            ->where('attribute_key', $attributeKey)
            ->value('truth_rating');

        return $value !== null ? (float) $value : null;
    }
}
