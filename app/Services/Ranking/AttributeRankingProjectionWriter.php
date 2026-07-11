<?php

namespace App\Services\Ranking;

use Illuminate\Support\Facades\Redis;

class AttributeRankingProjectionWriter
{
    public function upsert(string $attributeKey, int|string $playerId, float $rating, float $confidence): void
    {
        $rankingKey = 'ranking:' . $attributeKey;
        $playerId = (string) $playerId;

        Redis::zadd($rankingKey, $rating, $playerId);
        Redis::hset(
            $rankingKey . ':meta',
            $playerId,
            json_encode(['confidence' => $confidence], JSON_THROW_ON_ERROR),
        );
    }
}
