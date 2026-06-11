<?php

namespace App\Services\Ranking;

use Illuminate\Support\Facades\Redis;

class AttributeRankingService
{
    public function getRank(string $attributeKey, int $playerId): ?int
    {
        $rank = Redis::zrevrank(
            'ranking:' . $attributeKey,
            (string) $playerId,
        );

        if ($rank === null) {
            return null;
        }

        return $rank + 1;
    }

    public function isTopTen(string $attributeKey, int $playerId): bool
    {
        $rank = $this->getRank($attributeKey, $playerId);

        return $rank !== null && $rank <= 10;
    }

    public function getBadgeData(string $attributeKey, int $playerId): array
    {
        $rank = $this->getRank($attributeKey, $playerId);

        return [
            'rank' => $rank,
            'is_top_ten' => $rank !== null && $rank <= 10,
        ];
    }
}
