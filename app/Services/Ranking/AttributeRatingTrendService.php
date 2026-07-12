<?php

namespace App\Services\Ranking;

use Illuminate\Support\Facades\DB;

final class AttributeRatingTrendService
{
    /**
     * @param list<int> $playerIds
     * @return array<int, float>
     */
    public function sumDeltasForAttribute(int $attributeId, array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $trendRows = DB::table('votes')
            ->where('attribute_id', $attributeId)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('pre_rating_a')
            ->whereNotNull('post_rating_a')
            ->where(function ($query) use ($playerIds) {
                $query->whereIn('player_a_id', $playerIds)
                    ->orWhereIn('player_b_id', $playerIds);
            })
            ->get([
                'player_a_id',
                'player_b_id',
                'pre_rating_a',
                'post_rating_a',
                'pre_rating_b',
                'post_rating_b',
            ]);

        $trendByPlayer = [];

        foreach ($trendRows as $vote) {
            if (in_array((int) $vote->player_a_id, $playerIds, true)) {
                $deltaA = (float) $vote->post_rating_a - (float) $vote->pre_rating_a;
                $trendByPlayer[(int) $vote->player_a_id] = ($trendByPlayer[(int) $vote->player_a_id] ?? 0.0) + $deltaA;
            }

            if (
                $vote->player_b_id !== null &&
                in_array((int) $vote->player_b_id, $playerIds, true) &&
                $vote->pre_rating_b !== null &&
                $vote->post_rating_b !== null
            ) {
                $deltaB = (float) $vote->post_rating_b - (float) $vote->pre_rating_b;
                $trendByPlayer[(int) $vote->player_b_id] = ($trendByPlayer[(int) $vote->player_b_id] ?? 0.0) + $deltaB;
            }
        }

        return $trendByPlayer;
    }
}
