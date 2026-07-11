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

    /**
     * @return list<array{player_id: int, rating: float, confidence: float|null}>
     */
    public function getTopPlayers(string $attributeKey, int $limit = 5): array
    {
        if ($limit < 1) {
            return [];
        }

        $raw = Redis::zrevrange(
            'ranking:' . $attributeKey,
            0,
            $limit - 1,
            ['withscores' => true],
        );

        if ($raw === [] || $raw === null) {
            return [];
        }

        $entries = [];

        foreach ($raw as $playerId => $rating) {
            $entries[] = [
                'player_id' => (int) $playerId,
                'rating' => (float) $rating,
            ];
        }

        if ($entries === []) {
            return [];
        }

        $playerIds = array_map(
            static fn (array $entry): string => (string) $entry['player_id'],
            $entries,
        );

        $metaValues = Redis::hmget('ranking:' . $attributeKey . ':meta', $playerIds);

        foreach ($entries as $offset => &$entry) {
            $entry['confidence'] = $this->parseConfidence($metaValues[$offset] ?? null);
        }
        unset($entry);

        return $entries;
    }

    private function parseConfidence(mixed $metaValue): ?float
    {
        if (!is_string($metaValue) || $metaValue === '') {
            return null;
        }

        try {
            $decoded = json_decode($metaValue, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_key_exists('confidence', $decoded)) {
            return null;
        }

        return is_numeric($decoded['confidence'])
            ? (float) $decoded['confidence']
            : null;
    }
}
