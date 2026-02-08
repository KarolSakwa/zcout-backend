<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Duel;
use App\Services\RatingService;

class VoteController extends Controller
{

    public function store(Request $request, RatingService $ratingService)
    {
        $data = $request->validate([
            'attribute_key' => ['required', 'string'],
            'player_a_id' => ['required', 'integer'],
            'player_b_id' => ['required', 'integer'],
            'winner_id' => ['required', 'integer'],
        ]);

        $attribute = Attribute::where('key', $data['attribute_key'])->firstOrFail();

        $playerA = min($data['player_a_id'], $data['player_b_id']);
        $playerB = max($data['player_a_id'], $data['player_b_id']);

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id' => $playerA,
            'player_b_id' => $playerB,
        ]);

        $beforeRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $defaultSeed = 65.0;

        $before = [
            $playerA => [
                'rating' => (float) (($beforeRows[$playerA]->rating ?? $defaultSeed)),
                'votes_count' => (int) (($beforeRows[$playerA]->votes_count ?? 0)),
            ],
            $playerB => [
                'rating' => (float) (($beforeRows[$playerB]->rating ?? $defaultSeed)),
                'votes_count' => (int) (($beforeRows[$playerB]->votes_count ?? 0)),
            ],
        ];

        Vote::create([
            'duel_id' => $duel->id,
            'winner_id' => $data['winner_id'],
            'voter_hash' => null,
        ]);

        $winnerId = (int) $data['winner_id'];
        $loserId = $winnerId === (int) $data['player_a_id'] ? (int) $data['player_b_id'] : (int) $data['player_a_id'];

        $ratingService->applyVote($winnerId, $loserId, $attribute->id);

        $afterRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $players = [
            $playerA,
            $playerB,
        ];

        $payloadPlayers = array_map(function ($pid) use ($before, $afterRows, $defaultSeed) {
            $afterRating = (float) (($afterRows[$pid]->rating ?? $defaultSeed));
            $afterVotes = (int) (($afterRows[$pid]->votes_count ?? 0));
            $beforeRating = (float) $before[$pid]['rating'];

            return [
                'id' => (int) $pid,
                'rating' => $afterRating,
                'rating_before' => $beforeRating,
                'rating_after' => $afterRating,
                'delta' => $afterRating - $beforeRating,
                'votes_count' => $afterVotes,
            ];
        }, $players);

        return response()->json([
            'id' => null,
            'duel_id' => $duel->id,
            'attribute_id' => $attribute->id,
            'players' => $payloadPlayers,
        ]);
    }
}
