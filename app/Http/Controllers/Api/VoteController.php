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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VoteController extends Controller
{
    public function store(Request $request, RatingService $ratingService)
    {
        $payload = $this->payload($request);

        $v = Validator::make($payload, [
            'attribute_key' => ['required', 'string'],
            'player_a_id' => ['required', 'integer'],
            'player_b_id' => ['required', 'integer', 'different:player_a_id'],
            'winner_id' => ['required', 'integer'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $v->errors(),
            ], 422);
        }

        $data = $v->validated();

        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (!$attribute) {
            return response()->json(['message' => 'Attribute not found.'], 404);
        }

        $reqA = (int) $data['player_a_id'];
        $reqB = (int) $data['player_b_id'];
        $winnerId = (int) $data['winner_id'];

        if ($winnerId !== $reqA && $winnerId !== $reqB) {
            return response()->json(['message' => 'winner_id must be one of the duel players.'], 422);
        }

        $playerA = min($reqA, $reqB);
        $playerB = max($reqA, $reqB);

        $players = Player::query()
            ->select('id', 'position_id')
            ->with(['positionRef:id,short_label'])
            ->whereIn('id', [$playerA, $playerB])
            ->get()
            ->keyBy('id');

        if (!isset($players[$playerA]) || !isset($players[$playerB])) {
            return response()->json(['message' => 'Player not found.'], 404);
        }

        $posA = strtoupper((string) ($players[$playerA]->positionRef?->short_label ?? ''));
        $posB = strtoupper((string) ($players[$playerB]->positionRef?->short_label ?? ''));

        $beforeRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $beforeA = (float) ($beforeRows[$playerA]->rating ?? Seed::for($posA, $attribute->key));
        $beforeB = (float) ($beforeRows[$playerB]->rating ?? Seed::for($posB, $attribute->key));

        $duel = Duel::firstOrCreate([
            'attribute_id' => $attribute->id,
            'player_a_id' => $playerA,
            'player_b_id' => $playerB,
        ]);

        $loserId = $winnerId === $reqA ? $reqB : $reqA;

        $vote = null;
        $afterA = $beforeA;
        $afterB = $beforeB;

        $currentUserId = auth()->id();
        $isAuthed = $currentUserId !== null;

        $ratingWeight = $isAuthed ? 1.0 : 0.5;
        $confidenceWeight = $isAuthed ? 1.0 : 0.1;

        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        if (!$isAuthed && $anonId === '') {
            return response()->json([
                'message' => 'Missing X-Zcout-Anon header.',
            ], 400);
        }

        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        try {
            DB::transaction(function () use (
                $attribute,
                $duel,
                $playerA,
                $playerB,
                $winnerId,
                $ratingService,
                $loserId,
                $beforeA,
                $beforeB,
                $voterHash,
                &$vote,
                &$afterA,
                &$afterB,
                $currentUserId,
                $ratingWeight,
                $confidenceWeight
            ) {
                $vote = new Vote();
                $vote->source = 'duel';
                $vote->attribute_id = $attribute->id;
                $vote->duel_id = $duel->id;
                $vote->player_a_id = $playerA;
                $vote->player_b_id = $playerB;
                $vote->winner_id = $winnerId;
                $vote->user_id = $currentUserId;
                $vote->voter_hash = $voterHash;
                $vote->weight_applied = $ratingWeight;
                $vote->confidence_weight_applied = $confidenceWeight;
                $vote->weight_version = 1;
                $vote->reputation_at_vote = null;
                $vote->risk_score_at_vote = null;
                $vote->value = null;
                $vote->pre_rating_a = number_format($beforeA, 3, '.', '');
                $vote->pre_rating_b = number_format($beforeB, 3, '.', '');
                $vote->save();

                $ratingService->applyVote($winnerId, $loserId, $attribute->id, $ratingWeight, $confidenceWeight);

                $afterRows = PlayerAttributeRating::query()
                    ->where('attribute_id', $attribute->id)
                    ->whereIn('player_id', [$playerA, $playerB])
                    ->get()
                    ->keyBy('player_id');

                $afterA = (float) ($afterRows[$playerA]->rating ?? $beforeA);
                $afterB = (float) ($afterRows[$playerB]->rating ?? $beforeB);

                $vote->post_rating_a = number_format($afterA, 3, '.', '');
                $vote->post_rating_b = number_format($afterB, 3, '.', '');
                $vote->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = (string) $e->getMessage();
            if (stripos($msg, 'votes_unique_duel_voterhash') !== false) {
                return response()->json([
                    'message' => 'You already voted on this duel.',
                ], 409);
            }
            throw $e;
        }

        $afterRows = PlayerAttributeRating::query()
            ->where('attribute_id', $attribute->id)
            ->whereIn('player_id', [$playerA, $playerB])
            ->get()
            ->keyBy('player_id');

        $playersPayload = [];
        foreach ([$playerA, $playerB] as $pid) {
            $before = $pid === $playerA ? $beforeA : $beforeB;
            $afterRow = $afterRows[$pid] ?? null;
            $after = (float) ($afterRow?->rating ?? $before);

            $playersPayload[] = [
                'id' => (int) $pid,
                'rating_before' => $before,
                'rating_after' => $after,
                'delta' => $after - $before,
                'votes_count' => (int) ($afterRow?->votes_count ?? 0),
                'weight_sum' => (float) ($afterRow?->weight_sum ?? 0),
                'confidence' => (float) ($afterRow?->confidence ?? 0),
                'last_vote_at' => $afterRow?->last_vote_at,
            ];
        }

        $pop = DB::table('votes')
            ->select('winner_id', DB::raw('COUNT(*) as c'))
            ->where('duel_id', $duel->id)
            ->whereIn('winner_id', [$reqA, $reqB])
            ->groupBy('winner_id')
            ->pluck('c', 'winner_id');

        $votesA = (int) ($pop[$reqA] ?? 0);
        $votesB = (int) ($pop[$reqB] ?? 0);
        $votesTotal = $votesA + $votesB;

        return response()->json([
            'vote_id' => $vote?->id,
            'duel_id' => $duel->id,
            'attribute_id' => $attribute->id,
            'players' => $playersPayload,
            'popularity' => [
                'player_a_id' => $reqA,
                'player_b_id' => $reqB,
                'votes_a' => $votesA,
                'votes_b' => $votesB,
                'votes_total' => $votesTotal,
            ],
        ]);
    }

    public function storeDirect(Request $request)
    {
        $payload = $this->payload($request);

        $v = Validator::make($payload, [
            'attribute_key' => ['required', 'string'],
            'player_id' => ['required', 'integer'],
            'value' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $v->errors(),
            ], 422);
        }

        $data = $v->validated();

        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (!$attribute) {
            return response()->json(['message' => 'Attribute not found.'], 404);
        }

        $vote = new Vote();
        $vote->source = 'direct';
        $vote->attribute_id = $attribute->id;
        $vote->duel_id = null;
        $vote->player_a_id = (int) $data['player_id'];
        $vote->player_b_id = null;
        $vote->winner_id = null;
        $vote->user_id = auth()->id();
        $vote->voter_hash = null;
        $vote->weight_applied = 1.0;
        $vote->confidence_weight_applied = 1.0;
        $vote->weight_version = 1;
        $vote->reputation_at_vote = null;
        $vote->risk_score_at_vote = null;
        $vote->value = (int) $data['value'];
        $vote->created_at = now();
        $vote->save();

        return response()->json([
            'vote_id' => $vote->id,
            'attribute_id' => $attribute->id,
            'player_id' => (int) $data['player_id'],
            'value' => (int) $data['value'],
        ], 201);
    }

    private function payload(Request $request): array
    {
        $json = $request->json()->all();
        if (is_array($json) && count($json) > 0) {
            return $json;
        }

        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $all = $request->all();
        return is_array($all) ? $all : [];
    }
}
