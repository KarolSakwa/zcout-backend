<?php

namespace Tests\Unit\Actions;

use App\Actions\Duels\BuildDuelVoteContextAction;
use App\Data\ActionFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildDuelVoteContextActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_context_for_valid_duel_vote(): void
    {
        $fixture = $this->createDuelFixture();

        $context = app(BuildDuelVoteContextAction::class)->execute([
            'attribute_key' => 'passing',
            'duel_id' => $fixture['duel_id'],
            'winner_id' => $fixture['player_a_id'],
        ]);

        $this->assertSame($fixture['attribute_id'], $context->attribute->id);
        $this->assertSame($fixture['duel_id'], $context->duel->id);
        $this->assertSame($fixture['player_a_id'], $context->winnerId);
        $this->assertSame($fixture['player_b_id'], $context->loserId);
        $this->assertSame(
            min($fixture['player_a_id'], $fixture['player_b_id']),
            $context->canonicalPlayerAId,
        );
        $this->assertSame(
            max($fixture['player_a_id'], $fixture['player_b_id']),
            $context->canonicalPlayerBId,
        );
        $this->assertIsFloat($context->ratingBeforeA);
        $this->assertIsFloat($context->ratingBeforeB);
    }

    public function test_it_returns_action_failure_when_attribute_is_missing(): void
    {
        $fixture = $this->createDuelFixture();

        $result = app(BuildDuelVoteContextAction::class)->execute([
            'attribute_key' => 'nonexistent-attribute',
            'duel_id' => $fixture['duel_id'],
            'winner_id' => $fixture['player_a_id'],
        ]);

        $this->assertInstanceOf(ActionFailure::class, $result);
        $this->assertSame(404, $result->status);
        $this->assertSame('Attribute not found.', $result->message);
    }

    public function test_it_returns_action_failure_when_duel_is_missing(): void
    {
        $fixture = $this->createDuelFixture();

        $result = app(BuildDuelVoteContextAction::class)->execute([
            'attribute_key' => 'passing',
            'duel_id' => 999999,
            'winner_id' => $fixture['player_a_id'],
        ]);

        $this->assertInstanceOf(ActionFailure::class, $result);
        $this->assertSame(404, $result->status);
        $this->assertSame('Duel not found.', $result->message);
    }

    public function test_it_returns_action_failure_when_winner_is_not_in_duel(): void
    {
        $fixture = $this->createDuelFixture();

        $otherPlayerId = DB::table('players')->insertGetId([
            'name' => 'Other Player',
            'slug' => 'other-player',
            'position_id' => $fixture['position_id'],
        ]);

        $result = app(BuildDuelVoteContextAction::class)->execute([
            'attribute_key' => 'passing',
            'duel_id' => $fixture['duel_id'],
            'winner_id' => $otherPlayerId,
        ]);

        $this->assertInstanceOf(ActionFailure::class, $result);
        $this->assertSame(422, $result->status);
        $this->assertSame('winner_id must be one of the duel players.', $result->message);
    }

    /**
     * @return array{
     *     position_id: int,
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int,
     *     duel_id: int
     * }
     */
    private function createDuelFixture(): array
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'created_at' => now(),
        ]);

        return [
            'position_id' => $positionId,
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'duel_id' => $duelId,
        ];
    }
}
