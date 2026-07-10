<?php

namespace Tests\Unit\Actions;

use App\Actions\ApplyVoteEventToRatingsAction;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplyVoteEventToRatingsActionDuelLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_duel_locks_both_rating_rows_in_player_id_order_before_calculation(): void
    {
        $fixture = $this->createSharedPlayerFixture();

        $capturedLockSql = null;

        DB::listen(function ($query) use (&$capturedLockSql): void {
            $sql = strtolower($query->sql);

            if (
                str_contains($sql, 'player_attribute_ratings')
                && str_contains($sql, 'for update')
            ) {
                $capturedLockSql = $query->sql;
            }
        });

        DB::transaction(function () use ($fixture): void {
            app(ApplyVoteEventToRatingsAction::class)->executeDuel(
                attributeId: $fixture['attribute_id'],
                winnerId: $fixture['haaland_id'],
                loserId: $fixture['kane_id'],
            );
        });

        $this->assertNotNull($capturedLockSql, 'Expected a lockForUpdate query on player_attribute_ratings.');
        $this->assertStringContainsString('order by', strtolower($capturedLockSql));
        $this->assertStringContainsString('"player_id"', strtolower($capturedLockSql));
        $this->assertStringContainsString('for update', strtolower($capturedLockSql));
    }

    public function test_execute_duel_uses_locked_row_values_for_calculation_when_shared_player_is_updated_twice(): void
    {
        $fixture = $this->createSharedPlayerFixture();

        $action = app(ApplyVoteEventToRatingsAction::class);

        DB::transaction(function () use ($action, $fixture): void {
            $action->executeDuel(
                attributeId: $fixture['attribute_id'],
                winnerId: $fixture['haaland_id'],
                loserId: $fixture['kane_id'],
                ratingWeight: 1.0,
                confidenceWeight: 1.0,
            );
        });

        $afterFirstVote = (float) DB::table('player_attribute_ratings')
            ->where('player_id', $fixture['haaland_id'])
            ->where('attribute_id', $fixture['attribute_id'])
            ->value('rating');

        $ratingService = app(RatingService::class);

        $expectedAfterSecondVote = $ratingService->updateRatingsFromVote(
            $afterFirstVote,
            70.0,
            'ST',
            'ST',
            1,
            2,
            null,
            1.0,
        );

        DB::transaction(function () use ($action, $fixture): void {
            $action->executeDuel(
                attributeId: $fixture['attribute_id'],
                winnerId: $fixture['haaland_id'],
                loserId: $fixture['mbappe_id'],
                ratingWeight: 1.0,
                confidenceWeight: 1.0,
            );
        });

        $afterSecondVote = (float) DB::table('player_attribute_ratings')
            ->where('player_id', $fixture['haaland_id'])
            ->where('attribute_id', $fixture['attribute_id'])
            ->value('rating');

        $this->assertEqualsWithDelta(
            (float) $expectedAfterSecondVote['ratingA'],
            $afterSecondVote,
            0.001,
            'Second duel vote must calculate from the rating left by the first vote.',
        );
    }

    /**
     * @return array{
     *     attribute_id: int,
     *     haaland_id: int,
     *     kane_id: int,
     *     mbappe_id: int
     * }
     */
    private function createSharedPlayerFixture(): array
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'ST',
            'key' => 'st',
            'label' => 'Striker',
            'group' => 'ATTACK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $haalandId = DB::table('players')->insertGetId([
            'name' => 'Erling Haaland',
            'slug' => 'erling-haaland',
            'position_id' => $positionId,
        ]);

        $kaneId = DB::table('players')->insertGetId([
            'name' => 'Harry Kane',
            'slug' => 'harry-kane',
            'position_id' => $positionId,
        ]);

        $mbappeId = DB::table('players')->insertGetId([
            'name' => 'Kylian Mbappe',
            'slug' => 'kylian-mbappe',
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        foreach ([$haalandId, $kaneId, $mbappeId] as $playerId) {
            DB::table('player_attribute_ratings')->insert([
                'player_id' => $playerId,
                'attribute_id' => $attributeId,
                'rating' => $playerId === $haalandId ? 85.2 : 70.0,
                'votes_count' => 0,
                'rating_weight_sum' => 0,
                'confidence_weight_sum' => 0,
                'confidence' => 0,
                'last_vote_at' => null,
            ]);
        }

        return [
            'attribute_id' => $attributeId,
            'haaland_id' => $haalandId,
            'kane_id' => $kaneId,
            'mbappe_id' => $mbappeId,
        ];
    }
}
