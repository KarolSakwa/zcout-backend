<?php

namespace Tests\Unit\Services\Ranking;

use App\Services\Ranking\AttributeRatingTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributeRatingTrendServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sums_attribute_rating_deltas_from_votes_over_last_seven_days(): void
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'finishing',
            'label' => 'Finishing',
            'group' => 'ATTACK',
            'order' => 1,
            'scope' => 'both',
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Erling Haaland',
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Harry Kane',
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'status' => 'completed',
            'winner_id' => $playerAId,
            'created_at' => now(),
            'completed_at' => now(),
        ]);

        DB::table('votes')->insert([
            [
                'source' => 'duel',
                'duel_id' => $duelId,
                'attribute_id' => $attributeId,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
                'pre_rating_a' => 90.00,
                'post_rating_a' => 90.32,
                'pre_rating_b' => 88.00,
                'post_rating_b' => 88.00,
                'created_at' => now()->subDays(2),
            ],
            [
                'source' => 'duel',
                'duel_id' => $duelId,
                'attribute_id' => $attributeId,
                'player_a_id' => $playerBId,
                'player_b_id' => $playerAId,
                'winner_id' => $playerBId,
                'pre_rating_a' => 88.00,
                'post_rating_a' => 87.50,
                'pre_rating_b' => 90.32,
                'post_rating_b' => 90.32,
                'created_at' => now()->subDays(1),
            ],
        ]);

        $trends = (new AttributeRatingTrendService())->sumDeltasForAttribute(
            $attributeId,
            [$playerAId, $playerBId],
        );

        $this->assertSame(0.32, round($trends[$playerAId], 2));
        $this->assertSame(-0.5, round($trends[$playerBId], 2));
    }
}
