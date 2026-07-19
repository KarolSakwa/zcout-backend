<?php

namespace App\Services\Ranking;

use App\Models\PlayerAttributeRating;
use App\Models\PlayerOverall;
use Illuminate\Support\Facades\Redis;

/**
 * Full rebuild of Zcout ranking projections for the active Premier League pool.
 *
 * Clears attribute ZSETs + meta HASHes and the overall ZSET, then rewrites
 * only players matching Player::inCurrentPremierLeague().
 */
class RebuildRankingProjectionsAction
{
    public function __construct(
        private readonly AttributeRankingProjectionWriter $projectionWriter,
    ) {
    }

    public function handle(): void
    {
        $this->rebuildAttributeProjections();
        $this->rebuildOverallProjection();
    }

    public function rebuildAttributeProjections(): void
    {
        $this->clearAttributeProjectionKeys();

        PlayerAttributeRating::query()
            ->select([
                'player_id',
                'attribute_id',
                'rating',
                'confidence',
            ])
            ->whereHas('player', fn ($q) => $q->inCurrentPremierLeague())
            ->with('attribute:id,key')
            ->get()
            ->each(function (PlayerAttributeRating $row) {
                $this->projectionWriter->upsert(
                    $row->attribute->key,
                    $row->player_id,
                    (float) $row->rating,
                    (float) $row->confidence,
                );
            });
    }

    public function rebuildOverallProjection(): void
    {
        $this->clearOverallProjection();

        PlayerOverall::query()
            ->select('player_id', 'overall')
            ->whereHas('player', fn ($q) => $q->inCurrentPremierLeague())
            ->get()
            ->each(function (PlayerOverall $playerOverall) {
                Redis::zadd(
                    'ranking:overall',
                    (float) $playerOverall->overall,
                    (string) $playerOverall->player_id,
                );
            });
    }

    public function clearAttributeProjectionKeys(): void
    {
        $keys = Redis::keys('ranking:*') ?? [];

        foreach ($keys as $key) {
            $logicalKey = $this->logicalRankingKey((string) $key);

            if ($logicalKey === 'ranking:overall') {
                continue;
            }

            if (! $this->isAttributeProjectionKey($logicalKey)) {
                continue;
            }

            Redis::del($logicalKey);
        }
    }

    public function clearOverallProjection(): void
    {
        Redis::del('ranking:overall');
    }

    private function logicalRankingKey(string $key): string
    {
        $stripped = str_replace('laravel_database_', '', $key);

        if (str_contains($stripped, 'ranking:')) {
            return (string) preg_replace('/^.*?ranking:/', 'ranking:', $stripped);
        }

        return $stripped;
    }

    private function isAttributeProjectionKey(string $logicalKey): bool
    {
        if ($logicalKey === 'ranking:overall') {
            return false;
        }

        return (bool) preg_match('/^ranking:[^:]+(?::meta)?$/', $logicalKey);
    }
}
