<?php

namespace Tests\Unit\Services\Ranking;

use App\Services\Ranking\AttributeRankingProjectionWriter;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AttributeRankingProjectionWriterTest extends TestCase
{
    public function test_it_writes_rating_to_zset_and_confidence_meta_to_hash(): void
    {
        Redis::shouldReceive('zadd')
            ->once()
            ->with('ranking:finishing', 91.5, '123')
            ->andReturn(1);

        Redis::shouldReceive('hset')
            ->once()
            ->with('ranking:finishing:meta', '123', '{"confidence":84.25}')
            ->andReturn(1);

        (new AttributeRankingProjectionWriter())->upsert(
            attributeKey: 'finishing',
            playerId: 123,
            rating: 91.5,
            confidence: 84.25,
        );
    }

    public function test_meta_value_is_valid_json_with_confidence(): void
    {
        $capturedMetaJson = null;

        Redis::shouldReceive('zadd')->once()->andReturn(1);
        Redis::shouldReceive('hset')
            ->once()
            ->withArgs(function (string $key, string $playerId, string $metaJson) use (&$capturedMetaJson): bool {
                $capturedMetaJson = $metaJson;

                return $key === 'ranking:passing:meta'
                    && $playerId === '456';
            })
            ->andReturn(1);

        (new AttributeRankingProjectionWriter())->upsert(
            attributeKey: 'passing',
            playerId: '456',
            rating: 72.0,
            confidence: 33.33,
        );

        $this->assertIsString($capturedMetaJson);

        $decoded = json_decode($capturedMetaJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['confidence' => 33.33], $decoded);
    }
}
