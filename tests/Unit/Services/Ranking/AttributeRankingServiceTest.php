<?php

namespace Tests\Unit\Services\Ranking;

use App\Services\Ranking\AttributeRankingService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AttributeRankingServiceTest extends TestCase
{
    public function test_it_reads_top_players_with_confidence_from_redis(): void
    {
        Redis::shouldReceive('zrevrange')
            ->once()
            ->with('ranking:finishing', 0, 2, ['withscores' => true])
            ->andReturn([
                '123' => '94.25',
                '456' => '92.10',
            ]);

        Redis::shouldReceive('hmget')
            ->once()
            ->with('ranking:finishing:meta', ['123', '456'])
            ->andReturn(['{"confidence":84.25}', 'not-json']);

        $entries = (new AttributeRankingService())->getTopPlayers('finishing', 3);

        $this->assertSame([
            [
                'player_id' => 123,
                'rating' => 94.25,
                'confidence' => 84.25,
            ],
            [
                'player_id' => 456,
                'rating' => 92.1,
                'confidence' => null,
            ],
        ], $entries);
    }
}
