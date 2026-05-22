<?php

namespace App\Actions;

use App\Models\Attribute;
use App\Models\Player;
use App\Support\Seed;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InitializePlayerAttributeRatingsFromBaselineJsonAction
{
    public function execute(string $jsonPath): array
    {
        if (!is_file($jsonPath)) {
            throw new RuntimeException("Baseline JSON not found at [{$jsonPath}].");
        }

        $raw = file_get_contents($jsonPath);

        if ($raw === false) {
            throw new RuntimeException("Failed to read baseline JSON from [{$jsonPath}].");
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Baseline JSON is invalid at [{$jsonPath}].");
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
            ->select(['id', 'position_id', 'fd_position_id', 'manual_position_id'])
            ->with([
                'positionRef:id,short_label,key,label,group',
                'fdPositionRef:id,short_label,key,label,group',
                'manualPositionRef:id,short_label,key,label,group',
            ])
            ->orderBy('id')
            ->get();

        $rowsInserted = 0;
        $baselineJsonCount = 0;
        $seedFallbackCount = 0;
        $payload = [];

        foreach ($players as $player) {
            $position = $this->posCode($player);

            foreach ($attributes as $attribute) {
                $mapKey = (int) $player->id . '|' . (string) $attribute->key;

                if (array_key_exists($mapKey, $jsonRatings)) {
                    $rating = $jsonRatings[$mapKey];
                    $baselineJsonCount++;
                } else {
                    $rating = (float) Seed::for($position, $attribute->key);
                    $seedFallbackCount++;
                }

                $payload[] = [
                    'player_id' => (int) $player->id,
                    'attribute_id' => (int) $attribute->id,
                    'rating' => round($rating, 3),
                    'votes_count' => 0,
                    'rating_weight_sum' => config('rating.baseline.confidence_weight_sum'),
                    'confidence_weight_sum' => config('rating.baseline.confidence_weight_sum'),
                    'confidence' => config('rating.baseline.confidence'),
                    'last_vote_at' => null,
                ];

                if (count($payload) >= 1000) {
                    DB::table('player_attribute_ratings')->upsert(
                        $payload,
                        ['player_id', 'attribute_id'],
                        ['rating', 'votes_count', 'rating_weight_sum', 'confidence_weight_sum', 'confidence', 'last_vote_at']
                    );

                    $rowsInserted += count($payload);
                    $payload = [];
                }
            }
        }

        if ($payload !== []) {
            DB::table('player_attribute_ratings')->upsert(
                $payload,
                ['player_id', 'attribute_id'],
                ['rating', 'votes_count', 'rating_weight_sum', 'confidence_weight_sum', 'confidence', 'last_vote_at']
            );

            $rowsInserted += count($payload);
        }

        return [
            'baseline_json_path' => $jsonPath,
            'rows_initialized' => $rowsInserted,
            'baseline_json_count' => $baselineJsonCount,
            'seed_fallback_count' => $seedFallbackCount,
            'players_count' => $players->count(),
            'attributes_count' => $attributes->count(),
        ];
    }

    private function posCode(Player $player): string
    {
        $code = $player->effective_position_short
            ?? $player->effective_position_key
            ?? $player->effective_position_label
            ?? 'ST';

        return strtoupper((string) $code);
    }
}
