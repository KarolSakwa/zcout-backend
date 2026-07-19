<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCurrentPremierLeagueClub;
use Tests\TestCase;

class LiveFeedTopMoversTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCurrentPremierLeagueClub;

    public function test_it_returns_top_risers_for_last_7_days(): void
    {
        $clubId = $this->createCurrentPremierLeagueClub('Club '.uniqid('pl', true), 'club-'.uniqid('pl', true));
$playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        'club_id' => $clubId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        'club_id' => $clubId,
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
        'club_id' => $clubId,
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
        $clubId = $this->createCurrentPremierLeagueClub('Club '.uniqid('pl', true), 'club-'.uniqid('pl', true));
$playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        'club_id' => $clubId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        'club_id' => $clubId,
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
        'club_id' => $clubId,
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
        $clubId = $this->createCurrentPremierLeagueClub('Club '.uniqid('pl', true), 'club-'.uniqid('pl', true));
$playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
        'club_id' => $clubId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
        'club_id' => $clubId,
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

    public function test_top_movers_summary_applies_premier_league_filter_before_limit(): void
    {
        Cache::flush();

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
        ]);

        $inactiveClubId = $this->createInactiveClub();
        $activeClubId = $this->createCurrentPremierLeagueClub(
            'Club '.uniqid('pl', true),
            'club-'.uniqid('pl', true)
        );

        $dummyActivePlayerId = $this->createPlayer($activeClubId, 'Dummy Active');
        $dummyInactivePlayerId = $this->createPlayer($inactiveClubId, 'Dummy Inactive');

        $inactiveRiserIds = [];
        foreach ([10.0, 9.0, 8.0] as $index => $delta) {
            $playerId = $this->createPlayer($inactiveClubId, 'Inactive Riser '.$index);
            $inactiveRiserIds[] = $playerId;
            $this->insertMoverVote($attributeId, $playerId, $dummyInactivePlayerId, $delta);
        }

        $activeRiserIds = [];
        foreach ([7.0, 6.0, 5.0, 4.0, 3.0, 2.0] as $index => $delta) {
            $playerId = $this->createPlayer($activeClubId, 'Active Riser '.$index);
            $activeRiserIds[] = $playerId;
            $this->insertMoverVote($attributeId, $playerId, $dummyActivePlayerId, $delta);
        }

        $inactiveFallerIds = [];
        foreach ([-10.0, -9.0, -8.0] as $index => $delta) {
            $playerId = $this->createPlayer($inactiveClubId, 'Inactive Faller '.$index);
            $inactiveFallerIds[] = $playerId;
            $this->insertMoverVote($attributeId, $playerId, $dummyInactivePlayerId, $delta);
        }

        $activeFallerIds = [];
        foreach ([-7.0, -6.0, -5.0, -4.0, -3.0, -2.0] as $index => $delta) {
            $playerId = $this->createPlayer($activeClubId, 'Active Faller '.$index);
            $activeFallerIds[] = $playerId;
            $this->insertMoverVote($attributeId, $playerId, $dummyActivePlayerId, $delta);
        }

        $response = $this->getJson('/api/live/top-movers-summary?period=7d&limit=5');

        $response
            ->assertOk()
            ->assertJsonCount(5, 'risers')
            ->assertJsonCount(5, 'fallers');

        $riserPlayerIds = collect($response->json('risers'))->pluck('playerId')->all();
        $fallerPlayerIds = collect($response->json('fallers'))->pluck('playerId')->all();

        $this->assertSame(
            array_slice($activeRiserIds, 0, 5),
            $riserPlayerIds,
            'Risers should come from active Premier League clubs, ordered by delta.'
        );
        $this->assertSame(
            array_slice($activeFallerIds, 0, 5),
            $fallerPlayerIds,
            'Fallers should come from active Premier League clubs, ordered by delta.'
        );

        foreach ($inactiveRiserIds as $inactiveRiserId) {
            $this->assertNotContains($inactiveRiserId, $riserPlayerIds);
        }

        foreach ($inactiveFallerIds as $inactiveFallerId) {
            $this->assertNotContains($inactiveFallerId, $fallerPlayerIds);
        }
    }

    private function createInactiveClub(): int
    {
        return (int) DB::table('clubs')->insertGetId([
            'name' => 'Relegated '.uniqid('club', true),
            'slug' => 'relegated-'.uniqid('club', true),
            'is_current_premier_league' => false,
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(int $clubId, string $name): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $name,
            'slug' => str($name)->slug()->toString().'-'.uniqid(),
            'club_id' => $clubId,
        ]);
    }

    private function insertMoverVote(int $attributeId, int $playerAId, int $playerBId, float $deltaA): void
    {
        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        DB::table('votes')->insert([
            'source' => 'duel',
            'duel_id' => $duelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'winner_id' => $playerAId,
            'attribute_id' => $attributeId,
            'pre_rating_a' => 80,
            'post_rating_a' => 80 + $deltaA,
            'pre_rating_b' => 50,
            'post_rating_b' => 50,
            'created_at' => now()->subDay(),
        ]);
    }
}
