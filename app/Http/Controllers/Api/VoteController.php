<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use App\Services\RatingService;
use App\Support\Seed;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function store(Request $request, RatingService $ratingService)
    {
        $data = $request->validate([
            'attribute_key' => ['required', 'string'],
            'player_a_id'   => ['required', 'integer'],
            'player_b_id'   => ['required', 'integer'],
            'winner_id'     => ['required', 'integer'],
        ]);

        $attribute = Attribute::where('key', $data['attribute_key'])->firstOrFail();

        $playerA = min($data['player_a_id'], $data['player_b_id']);
        $playerB = max($data['player_a_id'], $data['player_b_id']);

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id'  => $playerA,
            'player_b_id'  => $playerB,
        ]);

        $beforeRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $meta = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label'])
            ->whereIn('id', [$playerA, $playerB])
            ->get()
            ->keyBy('id');

        $seed = function (int $pid) use ($meta, $attribute): float {
            $pos = strtoupper((string) ($meta[$pid]->positionRef?->short_label ?? ''));
            $attrKey = strtoupper((string) $attribute->key);
            return Seed::for($pos, $attrKey);
        };

        $before = [
            $playerA => [
                'rating'      => (float) ($beforeRows[$playerA]->rating ?? $seed($playerA)),
                'votes_count' => (int) ($beforeRows[$playerA]->votes_count ?? 0),
            ],
            $playerB => [
                'rating'      => (float) ($beforeRows[$playerB]->rating ?? $seed($playerB)),
                'votes_count' => (int) ($beforeRows[$playerB]->votes_count ?? 0),
            ],
        ];

        Vote::create([
            'duel_id'    => $duel->id,
            'winner_id'  => $data['winner_id'],
            'voter_hash' => null,
        ]);

        $winnerId = (int) $data['winner_id'];
        $loserId = $winnerId === (int) $data['player_a_id']
            ? (int) $data['player_b_id']
            : (int) $data['player_a_id'];

        $ratingService->applyVote($winnerId, $loserId, $attribute->id);

        $afterRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $players = [$playerA, $playerB];

        $payloadPlayers = array_map(function ($pid) use ($before, $afterRows, $seed) {
            $afterRating  = (float) ($afterRows[$pid]->rating ?? $seed((int) $pid));
            $afterVotes   = (int) ($afterRows[$pid]->votes_count ?? 0);
            $beforeRating = (float) $before[$pid]['rating'];

            return [
                'id'            => (int) $pid,
                'rating'        => $afterRating,
                'rating_before' => $beforeRating,
                'rating_after'  => $afterRating,
                'delta'         => $afterRating - $beforeRating,
                'votes_count'   => $afterVotes,
            ];
        }, $players);

        return response()->json([
            'id'           => null,
            'duel_id'      => $duel->id,
            'attribute_id' => $attribute->id,
            'players'      => $payloadPlayers,
        ]);
    }
}
