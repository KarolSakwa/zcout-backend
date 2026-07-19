<?php

namespace Tests\Unit\Services\Ranking;

use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AttributeRankingProjectionWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_rating_to_zset_and_confidence_meta_to_hash_for_active_player(): void
    {
        $playerId = $this->createPlayerInCurrentPremierLeague();

        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:finishing', 91.5, (string) $playerId)
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:finishing:meta', (string) $playerId, '{"confidence":84.25}')
            ->andReturn(1);

        (new AttributeRankingProjectionWriter())->upsert(
            attributeKey: 'finishing',
            playerId: $playerId,
            rating: 91.5,
            confidence: 84.25,
        );
    }

    public function test_meta_value_is_valid_json_with_confidence(): void
    {
        $playerId = $this->createPlayerInCurrentPremierLeague();
        $capturedMetaJson = null;

        Redis::shouldReceive('zadd')->once()->andReturn(1);
        Redis::shouldReceive('hset')
            ->once()
            ->withArgs(function (string $key, string $field, string $metaJson) use ($playerId, &$capturedMetaJson): bool {
                $capturedMetaJson = $metaJson;

                return $key === 'ranking:passing:meta'
                    && $field === (string) $playerId;
            })
            ->andReturn(1);

        (new AttributeRankingProjectionWriter())->upsert(
            attributeKey: 'passing',
            playerId: $playerId,
            rating: 72.0,
            confidence: 33.33,
        );

        $this->assertIsString($capturedMetaJson);

        $decoded = json_decode($capturedMetaJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['confidence' => 33.33], $decoded);
    }

    public function test_inactive_player_is_removed_from_attribute_meta_and_overall(): void
    {
        $playerId = $this->createPlayerInCurrentPremierLeague(active: false);

        Redis::shouldReceive('zrem')
            ->once()
            ->with('ranking:pace', (string) $playerId)
            ->andReturn(1);
        Redis::shouldReceive('hdel')
            ->once()
            ->with('ranking:pace:meta', (string) $playerId)
            ->andReturn(1);
        Redis::shouldReceive('zrem')
            ->once()
            ->with('ranking:overall', (string) $playerId)
            ->andReturn(1);

        (new AttributeRankingProjectionWriter())->upsert(
            attributeKey: 'pace',
            playerId: $playerId,
            rating: 50.0,
            confidence: 10.0,
        );
    }

    private function createPlayerInCurrentPremierLeague(bool $active = true): int
    {
        $clubId = (int) DB::table('clubs')->insertGetId([
            'name' => 'Writer Club '.uniqid(),
            'slug' => 'writer-club-'.uniqid(),
            'is_current_premier_league' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('players')->insertGetId([
            'name' => 'Writer Player',
            'slug' => 'writer-player-'.uniqid(),
            'club_id' => $clubId,
        ]);
    }
}
