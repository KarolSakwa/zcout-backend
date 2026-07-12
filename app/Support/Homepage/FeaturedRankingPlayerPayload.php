<?php

namespace App\Support\Homepage;

final class FeaturedRankingPlayerPayload
{
    public static function fromParts(
        int $playerId,
        string $playerName,
        float $rating,
        ?float $confidence,
        ?float $trend7d,
    ): array {
        return [
            'id' => (string) $playerId,
            'playerId' => $playerId,
            'player' => $playerName,
            'rating' => round($rating, 2),
            'confidence' => $confidence === null ? null : round($confidence, 2),
            'trend_7d' => $trend7d === null ? null : round($trend7d, 3),
        ];
    }
}
