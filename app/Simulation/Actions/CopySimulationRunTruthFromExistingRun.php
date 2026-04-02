<?php

namespace App\Simulation\Actions;

use App\Models\SimulationRun;
use App\Models\SimulationRunTruthRating;
use RuntimeException;

final class CopySimulationRunTruthFromExistingRun
{
    public function handle(int $sourceRunId, SimulationRun $targetRun): void
    {
        $exists = SimulationRun::query()->whereKey($sourceRunId)->exists();

        if (! $exists) {
            throw new RuntimeException("Truth source run [{$sourceRunId}] not found.");
        }

        SimulationRunTruthRating::query()
            ->where('simulation_run_id', $sourceRunId)
            ->orderBy('id')
            ->chunk(1000, function ($rows) use ($targetRun): void {
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        'simulation_run_id' => $targetRun->id,
                        'player_id' => (int) $row->player_id,
                        'attribute_key' => (string) $row->attribute_key,
                        'truth_rating' => round((float) $row->truth_rating, 2),
                        'source_label' => 'copied_from_run_' . $row->simulation_run_id,
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
