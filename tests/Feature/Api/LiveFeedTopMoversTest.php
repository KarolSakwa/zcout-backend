<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LiveFeedTopMoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_top_risers_for_last_7_days(): void
    {
        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
        ]);

        $duel1Id = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        $duel2Id = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerCId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subHours(12),
            'completed_at' => now()->subHours(12),
        ]);

        DB::table('votes')->insert([
            [
                'source' => 'duel',
                'duel_id' => $duel1Id,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
                'attribute_id' => $attributeId,
                'pre_rating_a' => 80,
                'post_rating_a' => 81.2,
                'pre_rating_b' => 79,
                'post_rating_b' => 78.4,
                'created_at' => now()->subDay(),
            ],
            [
                'source' => 'duel',
                'duel_id' => $duel2Id,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerCId,
                'winner_id' => $playerAId,
                'attribute_id' => $attributeId,
                'pre_rating_a' => 81.2,
                'post_rating_a' => 82.0,
                'pre_rating_b' => 84,
                'post_rating_b' => 83.5,
                'created_at' => now()->subHours(12),
            ],
        ]);

        $response = $this->getJson('/api/live/top-movers?direction=risers&period=7d&limit=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonStructure([
                'items' => [
                    '*' => [
                        'id',
                        'playerId',
                        'player',
                        'attributeKey',
                        'attributeLabel',
                        'delta',
                    ],
                ],
            ])
            ->assertJsonPath('items.0.playerId', $playerAId)
            ->assertJsonPath('items.0.player', 'Bukayo Saka')
            ->assertJsonPath('items.0.attributeKey', 'dribbling')
            ->assertJsonPath('items.0.attributeLabel', 'Dribbling')
            ->assertJsonPath('items.0.delta', '+2.00');
    }

    public function test_it_returns_top_fallers_for_last_7_days(): void
    {
        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
        ]);

        $duel1Id = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        $duel2Id = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerCId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subHours(12),
            'completed_at' => now()->subHours(12),
        ]);

        DB::table('votes')->insert([
            [
                'source' => 'duel',
                'duel_id' => $duel1Id,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
                'attribute_id' => $attributeId,
                'pre_rating_a' => 80,
                'post_rating_a' => 81.2,
                'pre_rating_b' => 79,
                'post_rating_b' => 78.4,
                'created_at' => now()->subDay(),
            ],
            [
                'source' => 'duel',
                'duel_id' => $duel2Id,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerCId,
                'winner_id' => $playerAId,
                'attribute_id' => $attributeId,
                'pre_rating_a' => 81.2,
                'post_rating_a' => 82.0,
                'pre_rating_b' => 84,
                'post_rating_b' => 83.5,
                'created_at' => now()->subHours(12),
            ],
        ]);

        $response = $this->getJson('/api/live/top-movers?direction=fallers&period=7d&limit=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonStructure([
                'items' => [
                    '*' => [
                        'id',
                        'playerId',
                        'player',
                        'attributeKey',
                        'attributeLabel',
                        'delta',
                    ],
                ],
            ])
            ->assertJsonPath('items.0.playerId', $playerBId)
            ->assertJsonPath('items.0.player', 'Cole Palmer')
            ->assertJsonPath('items.0.attributeKey', 'dribbling')
            ->assertJsonPath('items.0.attributeLabel', 'Dribbling')
            ->assertJsonPath('items.0.delta', '-0.60')
            ->assertJsonPath('items.1.playerId', $playerCId)
            ->assertJsonPath('items.1.delta', '-0.50');
    }

    public function test_it_ignores_votes_older_than_7_days(): void
    {
        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
        ]);

        $oldDuelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
        ]);

        DB::table('votes')->insert([
            'source' => 'duel',
            'duel_id' => $oldDuelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'winner_id' => $playerAId,
            'attribute_id' => $attributeId,
            'pre_rating_a' => 80,
            'post_rating_a' => 83,
            'pre_rating_b' => 79,
            'post_rating_b' => 76,
            'created_at' => now()->subDays(8),
        ]);

        $response = $this->getJson('/api/live/top-movers?direction=risers&period=7d&limit=5');

        $response
            ->assertOk()
            ->assertJson([
                'items' => [],
            ]);
    }
}
