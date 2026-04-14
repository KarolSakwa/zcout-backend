<?php

namespace App\Http\Controllers\Api;

use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use App\Models\VoteWeightLog;
use App\Services\RatingService;
use App\Support\Live\RecentVoteItem;
use App\Support\Seed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VoteController extends Controller
{
    private const WEIGHT_VERSION = 1;
    private const RATING_ALGORITHM_VERSION = 1;
    private const BASE_DUEL_WEIGHT = 1.0;
    private const AUTH_FACTOR_ANON = 0.5;
    private const AUTH_FACTOR_AUTHED = 1.0;
    private const TRUST_RATING_FACTOR_DEFAULT = 1.0;
    private const TRUST_CONFIDENCE_FACTOR_ANON = 0.2;
    private const TRUST_CONFIDENCE_FACTOR_AUTHED = 1.0;
    private const INTEGRITY_FACTOR_DEFAULT = 1.0;
    private const BIAS_FACTOR_DEFAULT = 1.0;
    private const ACTIVITY_FACTOR_DEFAULT = 1.0;
    private const ROLE_FACTOR_DEFAULT = 1.0;

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
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with(['positionRef:id,short_label', 'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label'])
            ->whereIn('id', [$playerA, $playerB])
            ->get()
            ->keyBy('id');

        if (!isset($players[$playerA]) || !isset($players[$playerB])) {
            return response()->json(['message' => 'Player not found.'], 404);
        }

        $posA = strtoupper((string) ($players[$playerA]->effective_position_short ?? ''));
        $posB = strtoupper((string) ($players[$playerB]->effective_position_short ?? ''));

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

        $weightVersion = self::WEIGHT_VERSION;
        $ratingAlgorithmVersion = self::RATING_ALGORITHM_VERSION;
        $baseDuelWeight = self::BASE_DUEL_WEIGHT;
        $authFactor = $isAuthed ? self::AUTH_FACTOR_AUTHED : self::AUTH_FACTOR_ANON;
        $trustRatingFactor = self::TRUST_RATING_FACTOR_DEFAULT;
        $trustConfidenceFactor = $isAuthed ? self::TRUST_CONFIDENCE_FACTOR_AUTHED : self::TRUST_CONFIDENCE_FACTOR_ANON;
        $integrityFactor = self::INTEGRITY_FACTOR_DEFAULT;
        $biasFactor = self::BIAS_FACTOR_DEFAULT;
        $activityFactor = self::ACTIVITY_FACTOR_DEFAULT;
        $roleFactor = self::ROLE_FACTOR_DEFAULT;

        $ratingWeight = $baseDuelWeight
            * $authFactor
            * $trustRatingFactor
            * $integrityFactor
            * $biasFactor
            * $activityFactor
            * $roleFactor;

        $confidenceWeight = $baseDuelWeight
            * $authFactor
            * $trustConfidenceFactor
            * $integrityFactor
            * $biasFactor
            * $activityFactor
            * $roleFactor;

        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        $lockKeys = [];
        if ($anonId !== '') $lockKeys[] = $anonId;
        if ($isAuthed) $lockKeys[] = 'user:' . $currentUserId;

        $lockKey = $anonId !== '' ? $anonId : ($isAuthed ? ('user:' . $currentUserId) : null);

        if (!$lockKey) {
            return response()->json([
                'message' => 'Missing voter id.',
            ], 400);
        }

        $voterHash = hash_hmac('sha256', $lockKey, (string) config('app.key'));

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
                $confidenceWeight,
                $weightVersion,
                $ratingAlgorithmVersion,
                $baseDuelWeight,
                $authFactor,
                $trustRatingFactor,
                $trustConfidenceFactor,
                $integrityFactor,
                $biasFactor,
                $activityFactor,
                $roleFactor
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
                $vote->weight_version = $weightVersion;
                $vote->reputation_at_vote = null;
                $vote->risk_score_at_vote = null;
                $vote->value = null;
                $vote->pre_rating_a = number_format($beforeA, 3, '.', '');
                $vote->pre_rating_b = number_format($beforeB, 3, '.', '');
                $vote->save();

                VoteWeightLog::create([
                    'vote_id' => $vote->id,
                    'weight_version' => $weightVersion,
                    'rating_algorithm_version' => $ratingAlgorithmVersion,
                    'base_duel_weight' => $baseDuelWeight,
                    'auth_factor' => $authFactor,
                    'trust_rating_factor' => $trustRatingFactor,
                    'trust_confidence_factor' => $trustConfidenceFactor,
                    'integrity_factor' => $integrityFactor,
                    'bias_factor' => $biasFactor,
                    'activity_factor' => $activityFactor,
                    'role_factor' => $roleFactor,
                    'rating_weight_applied' => $ratingWeight,
                    'confidence_weight_applied' => $confidenceWeight,
                ]);

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

            $recentVoteRow = DB::table('votes as v')
                ->join('players as winner_player', 'winner_player.id', '=', 'v.winner_id')
                ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
                ->join('players as player_a', 'player_a.id', '=', 'v.player_a_id')
                ->join('players as player_b', 'player_b.id', '=', 'v.player_b_id')
                ->where('v.id', $vote->id)
                ->select([
                    'v.id',
                    'v.winner_id',
                    'v.player_a_id',
                    'v.player_b_id',
                    DB::raw('COALESCE(winner_player.manual_display_name, winner_player.fd_name, winner_player.name) as winner_name'),
                    DB::raw('COALESCE(player_a.manual_display_name, player_a.fd_name, player_a.name) as player_a_name'),
                    DB::raw('COALESCE(player_b.manual_display_name, player_b.fd_name, player_b.name) as player_b_name'),
                    'a.key as attribute_key',
                    'a.label as attribute_label',
                ])
                ->first();

            if ($recentVoteRow) {
                event(new RecentVoteCreated(RecentVoteItem::fromRow($recentVoteRow)));
            }

            Cache::forget('live:top-movers-summary:7d:5');
            event(new TopMoversMaybeChanged());
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = (string) $e->getMessage();
            $code = (string) $e->getCode();

            if ($code === '23505' || stripos($msg, 'votes_unique_duel_voterhash') !== false) {
                DB::table('voter_duel_locks')->whereIn('voter_hash', $lockKeys)->delete();

                Log::warning('vote.duel_duplicate_vote', [
                    'duel_id' => $duel->id ?? null,
                    'attribute_id' => $attribute->id ?? null,
                    'player_a_id' => $playerA ?? null,
                    'player_b_id' => $playerB ?? null,
                    'winner_id' => $winnerId ?? null,
                    'user_id' => $currentUserId,
                    'voter_hash' => $voterHash ?? null,
                    'error_code' => $code,
                ]);

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
                'rating_weight_sum' => (float) ($afterRow?->rating_weight_sum ?? 0),
                'confidence_weight_sum' => (float) ($afterRow?->confidence_weight_sum ?? 0),
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

        DB::table('voter_duel_locks')->whereIn('voter_hash', $lockKeys)->delete();

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

    public function storeDirect(Request $request, \App\Actions\StoreDirectVoteAction $storeDirectVoteAction)
    {
        $payload = $this->payload($request);

        $v = Validator::make($payload, [
            'attribute_key' => ['required', 'string'],
            'player_id' => ['required', 'integer'],
            'value' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        if ($v->fails()) {
            Log::warning('direct_vote.validation_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'attribute_key' => $payload['attribute_key'] ?? null,
                'value' => $payload['value'] ?? null,
                'errors' => $v->errors()->toArray(),
            ]);

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $v->errors(),
            ], 422);
        }

        $result = $storeDirectVoteAction->execute(
            $v->validated(),
            (int) auth()->id(),
        );

        if (($result['status'] ?? 500) >= 400) {
            Log::warning('direct_vote.submit_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'attribute_key' => $payload['attribute_key'] ?? null,
                'value' => $payload['value'] ?? null,
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
            ]);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function submitScoutReport(Request $request, \App\Actions\SubmitScoutReportAction $submitScoutReportAction)
    {
        $payload = $this->payload($request);

        $v = Validator::make($payload, [
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'votes' => ['array', 'max:6'],
            'votes.*.attribute_key' => ['required', 'string', 'exists:attributes,key'],
            'votes.*.value' => ['required', 'integer', 'min:1', 'max:99'],
            'skipped_attribute_ids' => ['array', 'max:6'],
            'skipped_attribute_ids.*' => ['integer', 'exists:attributes,id'],
        ]);

        $v->after(function ($validator) use ($payload) {
            $votes = $payload['votes'] ?? [];
            $skips = $payload['skipped_attribute_ids'] ?? [];

            if (count($votes) === 0 && count($skips) === 0) {
                $validator->errors()->add('payload', 'At least one vote or one skip is required.');
                return;
            }

            $voteKeys = collect($votes)
                ->pluck('attribute_key')
                ->filter()
                ->values();

            if ($voteKeys->count() !== $voteKeys->unique()->count()) {
                $validator->errors()->add('votes', 'Duplicate attribute_key in votes payload.');
            }

            $skipIds = collect($skips)
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($skipIds->count() !== $skipIds->unique()->count()) {
                $validator->errors()->add('skipped_attribute_ids', 'Duplicate attribute_id in skipped payload.');
            }

            $touchedCount = $voteKeys->count() + $skipIds->unique()->count();

            if ($touchedCount > 6) {
                $validator->errors()->add('payload', 'Scout report submit can contain at most 6 touched attributes.');
            }

            if ($voteKeys->isNotEmpty() && $skipIds->isNotEmpty()) {
                $overlapExists = \App\Models\Attribute::query()
                    ->whereIn('key', $voteKeys->all())
                    ->whereIn('id', $skipIds->all())
                    ->exists();

                if ($overlapExists) {
                    $validator->errors()->add('payload', 'The same attribute cannot be voted and skipped in one submit.');
                }
            }
        });

        if ($v->fails()) {
            Log::warning('scout_report.validation_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'votes_count' => count($payload['votes'] ?? []),
                'skips_count' => count($payload['skipped_attribute_ids'] ?? []),
                'errors' => $v->errors()->toArray(),
            ]);

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $v->errors(),
            ], 422);
        }

        $result = $submitScoutReportAction->execute(
            (int) auth()->id(),
            $v->validated(),
        );

        if (($result['status'] ?? 500) >= 400) {
            Log::warning('scout_report.submit_failed', [
                'user_id' => auth()->id(),
                'player_id' => $payload['player_id'] ?? null,
                'votes_count' => count($payload['votes'] ?? []),
                'skips_count' => count($payload['skipped_attribute_ids'] ?? []),
                'status' => $result['status'] ?? null,
                'body' => $result['body'] ?? null,
            ]);
        }

        return response()->json($result['body'], $result['status']);
    }

    public function scoutReportAttributes(
        \Illuminate\Http\Request $request,
        \App\Models\Player $player,
        \App\Actions\GetScoutReportAttributesAction $getScoutReportAttributesAction
    ) {
        $result = $getScoutReportAttributesAction->execute(
            (int) auth()->id(),
            (int) $player->id,
        );

        return response()->json($result['body'], $result['status']);
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
