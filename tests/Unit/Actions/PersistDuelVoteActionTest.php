<?php

namespace Tests\Unit\Actions;

use App\Actions\PersistDuelVoteAction;
use App\Data\DuelVote\DuelVoteContext;
use App\Data\DuelVote\VoterIdentity;
use App\Models\Attribute;
use App\Models\Duel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersistDuelVoteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_vote_and_vote_weight_log_in_transaction(): void
    {
        $fixture = $this->createDuelFixture();

        $attribute = Attribute::query()->findOrFail($fixture['attribute_id']);
        $duel = Duel::query()->findOrFail($fixture['duel_id']);

        $context = new DuelVoteContext(
            attribute: $attribute,
            duel: $duel,
            winnerId: $fixture['player_a_id'],
            loserId: $fixture['player_b_id'],
            canonicalPlayerAId: min($fixture['player_a_id'], $fixture['player_b_id']),
            canonicalPlayerBId: max($fixture['player_a_id'], $fixture['player_b_id']),
            duelPlayerAId: $fixture['player_a_id'],
            duelPlayerBId: $fixture['player_b_id'],
            ratingBeforeA: 70.0,
            ratingBeforeB: 68.0,
        );

        $identity = new VoterIdentity(
            userId: null,
            isAuthenticated: false,
            lockKeys: ['persist-test-anon'],
            lockKey: 'persist-test-anon',
            voterHash: hash_hmac('sha256', 'persist-test-anon', (string) config('app.key')),
        );

        $result = app(PersistDuelVoteAction::class)->execute(
            context: $context,
            identity: $identity,
            ratingWeight: 0.5,
            confidenceWeight: 0.1,
            occurredAt: now(),
        );

        $this->assertDatabaseHas('votes', [
            'id' => $result->vote->id,
            'source' => 'duel',
            'duel_id' => $fixture['duel_id'],
            'attribute_id' => $fixture['attribute_id'],
            'winner_id' => $fixture['player_a_id'],
            'voter_hash' => $identity->voterHash,
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
        ]);

        $this->assertDatabaseHas('vote_weight_logs', [
            'vote_id' => $result->vote->id,
            'weight_version' => 1,
            'rating_algorithm_version' => 1,
            'rating_weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
        ]);

        $this->assertNotNull($result->vote->post_rating_a);
        $this->assertNotNull($result->vote->post_rating_b);
        $this->assertIsFloat($result->ratingAfterA);
        $this->assertIsFloat($result->ratingAfterB);
    }

    /**
     * @return array{
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
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'duel_id' => $duelId,
        ];
    }
}
