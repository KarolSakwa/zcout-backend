<?php

namespace App\Simulation\Truth;

use App\Models\Attribute;
use App\Models\Player;
use App\Models\SimulationRun;
use App\Models\SimulationRunTruthRating;
use App\Simulation\Contracts\TruthProvider;
use App\Support\Seed;
use RuntimeException;

final class BaselineJsonTruthProvider implements TruthProvider
{
    public function __construct(
        private readonly string $jsonPath,
    ) {
    }

    public function snapshotForRun(SimulationRun $run): void
    {
        if (!is_file($this->jsonPath)) {
            throw new RuntimeException("Baseline JSON not found at [{$this->jsonPath}].");
        }

        $raw = file_get_contents($this->jsonPath);

        if ($raw === false) {
            throw new RuntimeException("Failed to read baseline JSON from [{$this->jsonPath}].");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Baseline JSON is invalid at [{$this->jsonPath}].");
        }

        $playersPayload = $decoded['players'] ?? null;

        if (!is_array($playersPayload)) {
            throw new RuntimeException("Baseline JSON does not contain a valid [players] object.");
        }

        $jsonRatings = [];

        foreach ($playersPayload as $playerId => $playerData) {
            if (!is_array($playerData)) {
                continue;
            }

            $attributes = $playerData['attributes'] ?? null;

            if (!is_array($attributes)) {
                continue;
            }

            foreach ($attributes as $attributeKey => $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $jsonRatings[(int) $playerId . '|' . (string) $attributeKey] = (float) $value;
            }
        }

        $attributes = Attribute::query()
            ->select(['id', 'key'])
            ->orderBy('id')
            ->get();

        $players = Player::query()
            ->inCurrentPremierLeague()
            ->select(['id', 'position_id', 'fd_position_id', 'manual_position_id'])
            ->with(['positionRef:id,short_label', 'fdPositionRef:id,short_label,key,label', 'manualPositionRef:id,short_label,key,label'])
            ->orderBy('id')
            ->get();

        $payload = [];

        foreach ($players as $player) {
            $position = strtoupper((string) ($player->effective_position_short ?? 'CM'));

            foreach ($attributes as $attribute) {
                $mapKey = (int) $player->id . '|' . (string) $attribute->key;

                if (array_key_exists($mapKey, $jsonRatings)) {
                    $truthRating = $jsonRatings[$mapKey];
                    $sourceLabel = 'baseline_json';
                } else {
                    continue;
                }

                $payload[] = [
                    'simulation_run_id' => $run->id,
                    'player_id' => (int) $player->id,
                    'attribute_key' => (string) $attribute->key,
                    'truth_rating' => round($truthRating, 2),
                    'source_label' => $sourceLabel,
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
