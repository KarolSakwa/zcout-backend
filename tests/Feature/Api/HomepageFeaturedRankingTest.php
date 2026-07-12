<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HomepageFeaturedRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_null_attribute_and_empty_players_when_no_attributes_exist(): void
    {
        $response = $this->getJson('/api/homepage/featured-ranking');

        $response
            ->assertOk()
            ->assertExactJson([
                'attribute' => null,
                'players' => [],
            ]);
    }

    public function test_it_returns_featured_ranking_contract_with_redis_data(): void
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'finishing',
            'label' => 'Finishing',
            'group' => 'ATTACK',
            'order' => 1,
            'scope' => 'both',
        ]);

        $this->assertGreaterThan(0, $attributeId);

        $playerOneId = DB::table('players')->insertGetId([
            'name' => 'Erling Haaland',
            'slug' => 'erling-haaland',
        ]);

        $playerTwoId = DB::table('players')->insertGetId([
            'name' => 'Harry Kane',
            'slug' => 'harry-kane',
        ]);

        $missingPlayerId = 999_999;

        Redis::shouldReceive('zrevrange')
            ->once()
            ->with('ranking:finishing', 0, 4, ['withscores' => true])
            ->andReturn([
                (string) $playerOneId => '94.25',
                (string) $playerTwoId => '92.10',
                (string) $missingPlayerId => '90.00',
            ]);

        Redis::shouldReceive('hmget')
            ->once()
            ->with('ranking:finishing:meta', [
                (string) $playerOneId,
                (string) $playerTwoId,
                (string) $missingPlayerId,
            ])
            ->andReturn([
                '{"confidence":84.25}',
                null,
                '{"confidence":70.00}',
            ]);

        $response = $this->getJson('/api/homepage/featured-ranking');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'attribute' => ['key', 'label', 'icon'],
                'players' => [
                    '*' => ['id', 'playerId', 'player', 'rating', 'confidence', 'trend_7d'],
                ],
            ])
            ->assertJsonPath('attribute.key', 'finishing')
            ->assertJsonPath('attribute.label', 'Finishing')
            ->assertJsonPath('attribute.icon', '/icons/attribute-icons/finishing.svg')
            ->assertJsonCount(2, 'players')
            ->assertJsonPath('players.0.playerId', $playerOneId)
            ->assertJsonPath('players.0.player', 'Erling Haaland')
            ->assertJsonPath('players.0.rating', 94.25)
            ->assertJsonPath('players.0.confidence', 84.25)
            ->assertJsonPath('players.0.trend_7d', null)
            ->assertJsonPath('players.1.playerId', $playerTwoId)
            ->assertJsonPath('players.1.player', 'Harry Kane')
            ->assertJsonPath('players.1.rating', 92.1)
            ->assertJsonPath('players.1.confidence', null);
    }

    public function test_it_returns_attribute_with_empty_players_when_ranking_is_empty(): void
    {
        DB::table('attributes')->insert([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        Redis::shouldReceive('zrevrange')
            ->once()
            ->with('ranking:pace', 0, 4, ['withscores' => true])
            ->andReturn([]);

        $response = $this->getJson('/api/homepage/featured-ranking');

        $response
            ->assertOk()
            ->assertJsonPath('attribute.key', 'pace')
            ->assertJsonPath('players', []);
    }
}
