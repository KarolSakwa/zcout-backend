<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LiveFeedRecentVotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_recent_duel_votes_in_expected_shape(): void
    {
        $winnerId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        ]);

        $loserId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $winnerId,
            'player_b_id' => $loserId,
            'status' => 'completed',
            'winner_id' => $winnerId,
            'created_at' => now(),
            'completed_at' => now(),
        ]);

        DB::table('votes')->insert([
            'source' => 'duel',
            'duel_id' => $duelId,
            'player_a_id' => $winnerId,
            'player_b_id' => $loserId,
            'winner_id' => $winnerId,
            'attribute_id' => $attributeId,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/live/recent-votes?limit=3');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'items' => [
                    '*' => [
                        'id',
                        'winner',
                        'loser',
                        'winnerPlayerId',
                        'loserPlayerId',
                        'attributeKey',
                        'attributeLabel',
                    ],
                ],
            ])
            ->assertJsonPath('items.0.winner', 'Bukayo Saka')
            ->assertJsonPath('items.0.loser', 'Cole Palmer')
            ->assertJsonPath('items.0.winnerPlayerId', $winnerId)
            ->assertJsonPath('items.0.loserPlayerId', $loserId)
            ->assertJsonPath('items.0.attributeKey', 'dribbling')
            ->assertJsonPath('items.0.attributeLabel', 'Dribbling');

        $this->assertIsString(data_get($response->json(), 'items.0.id'));
    }

    public function test_it_returns_recent_votes_in_desc_order_and_respects_limit(): void
    {
        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a',
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b',
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Player C',
            'slug' => 'player-c',
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
        ]);

        $olderDuelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
        ]);

        $middleDuelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerCId,
            'status' => 'completed',
            'winner_id' => $playerCId,
            'created_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        $newestDuelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerBId,
            'player_b_id' => $playerCId,
            'status' => 'completed',
            'winner_id' => $playerBId,
            'created_at' => now(),
            'completed_at' => now(),
        ]);

        $olderVoteId = DB::table('votes')->insertGetId([
            'source' => 'duel',
            'duel_id' => $olderDuelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'winner_id' => $playerAId,
            'attribute_id' => $attributeId,
            'created_at' => now()->subMinutes(2),
        ]);

        $middleVoteId = DB::table('votes')->insertGetId([
            'source' => 'duel',
            'duel_id' => $middleDuelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerCId,
            'winner_id' => $playerCId,
            'attribute_id' => $attributeId,
            'created_at' => now()->subMinute(),
        ]);

        $newestVoteId = DB::table('votes')->insertGetId([
            'source' => 'duel',
            'duel_id' => $newestDuelId,
            'player_a_id' => $playerBId,
            'player_b_id' => $playerCId,
            'winner_id' => $playerBId,
            'attribute_id' => $attributeId,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/live/recent-votes?limit=2');

        $response->assertOk();

        $items = $response->json('items');

        $this->assertCount(2, $items);
        $this->assertSame((string) $newestVoteId, $items[0]['id']);
        $this->assertSame((string) $middleVoteId, $items[1]['id']);
        $this->assertNotSame((string) $olderVoteId, $items[0]['id']);
        $this->assertNotSame((string) $olderVoteId, $items[1]['id']);
    }
}
