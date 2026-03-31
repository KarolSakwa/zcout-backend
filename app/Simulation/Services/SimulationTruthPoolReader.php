<?php

namespace App\Simulation\Services;

use App\Models\SimulationRunTruthRating;

final class SimulationTruthPoolReader
{
    public function getAttributeKeysForRun(int $runId): array
    {
        return SimulationRunTruthRating::query()
            ->where('simulation_run_id', $runId)
            ->distinct()
            ->orderBy('attribute_key')
            ->pluck('attribute_key')
            ->map(fn ($key) => (string) $key)
            ->all();
    }

    public function getPlayerIdsForRunAndAttribute(int $runId, string $attributeKey): array
    {
        return SimulationRunTruthRating::query()
            ->where('simulation_run_id', $runId)
            ->where('attribute_key', $attributeKey)
            ->distinct()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
