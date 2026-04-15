<?php

namespace Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VoteFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_duel_vote_persists_vote_and_updates_both_rating_rows(): void
    {
        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerAId = $this->createPlayer([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $response = $this->postJson(
            '/api/votes',
            [
                'attribute_key' => 'passing',
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
            ],
            [
                'X-Zcout-Anon' => 'anon-smoke-1',
            ]
        );

        $response->assertSuccessful();

        $this->assertDatabaseHas('votes', [
            'source' => 'duel',
            'attribute_id' => $attributeId,
            'player_a_id' => min($playerAId, $playerBId),
            'player_b_id' => max($playerAId, $playerBId),
            'winner_id' => $playerAId,
            'weight_version' => 1,
        ]);

        $vote = DB::table('votes')->first();

        $this->assertNotNull($vote);
        $this->assertEquals(0.5, (float) $vote->weight_applied);
        $this->assertEquals(0.1, (float) $vote->confidence_weight_applied);
        $this->assertNotNull($vote->pre_rating_a);
        $this->assertNotNull($vote->pre_rating_b);
        $this->assertNotNull($vote->post_rating_a);
        $this->assertNotNull($vote->post_rating_b);

        $playerARatingRow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerAId)
            ->where('attribute_id', $attributeId)
            ->first();

        $playerBRatingRow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerBId)
            ->where('attribute_id', $attributeId)
            ->first();

        $this->assertNotNull($playerARatingRow);
        $this->assertNotNull($playerBRatingRow);

        $this->assertSame(1, (int) $playerARatingRow->votes_count);
        $this->assertSame(1, (int) $playerBRatingRow->votes_count);

        $this->assertEquals(0.5, (float) $playerARatingRow->rating_weight_sum);
        $this->assertEquals(0.5, (float) $playerBRatingRow->rating_weight_sum);

        $this->assertEquals(0.1, (float) $playerARatingRow->confidence_weight_sum);
        $this->assertEquals(0.1, (float) $playerBRatingRow->confidence_weight_sum);

        $this->assertEquals(0.1, (float) $playerARatingRow->confidence);
        $this->assertEquals(0.1, (float) $playerBRatingRow->confidence);

        $this->assertNotNull($playerARatingRow->last_vote_at);
        $this->assertNotNull($playerBRatingRow->last_vote_at);
    }

    private function createPosition(array $data): int
    {
        return (int) DB::table('positions')->insertGetId([
            'short_label' => $data['short_label'],
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(array $data): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'position_id' => $data['position_id'],
            'club_id' => null,
            'country_id' => null,
            'number' => null,
            'date_of_birth' => null,
        ]);
    }

    private function createAttribute(array $data): int
    {
        return (int) DB::table('attributes')->insertGetId([
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'scope' => $data['scope'],
        ]);
    }
}
