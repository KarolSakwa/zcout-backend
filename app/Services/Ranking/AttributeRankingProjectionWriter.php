<?php

namespace App\Services\Ranking;

use App\Models\Player;
use Illuminate\Support\Facades\Redis;

class AttributeRankingProjectionWriter
{
    public function upsert(string $attributeKey, int|string $playerId, float $rating, float $confidence): void
    {
        $rankingKey = 'ranking:' . $attributeKey;
        $playerId = (string) $playerId;

        if (! $this->isInCurrentPremierLeague((int) $playerId)) {
            Redis::zrem($rankingKey, $playerId);
            Redis::hdel($rankingKey . ':meta', $playerId);
            Redis::zrem('ranking:overall', $playerId);

            return;
        }

        Redis::zadd($rankingKey, $rating, $playerId);
        Redis::hset(
            $rankingKey . ':meta',
            $playerId,
            json_encode(['confidence' => $confidence], JSON_THROW_ON_ERROR),
        );
    }

    private function isInCurrentPremierLeague(int $playerId): bool
    {
        return Player::query()
            ->whereKey($playerId)
            ->inCurrentPremierLeague()
            ->exists();
    }
}
