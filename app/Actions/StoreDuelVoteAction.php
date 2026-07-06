<?php

namespace App\Actions;

use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Models\Attribute;
use App\Models\Duel;
use App\Models\Player;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use App\Models\VoteWeightLog;
use App\Services\Ranking\AttributeRankingService;
use App\Support\Live\RecentVoteItem;
use App\Support\Seed;
use App\Support\VoteWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StoreDuelVoteAction
{
    private const WEIGHT_VERSION = 1;
    private const RATING_ALGORITHM_VERSION = 1;

    public function __construct(
        private readonly ApplyVoteEventToRatingsAction $applyVoteEventToRatingsAction,
        private readonly AttributeRankingService $attributeRankingService,
        private readonly VoteWeightResolver $voteWeightResolver,
    ) {
    }

    public function execute(array $data, Request $request): array
    {
        $attribute = Attribute::query()
            ->select('id', 'key')
            ->where('key', $data['attribute_key'])
            ->first();

        if (!$attribute) {
            return $this->error(404, ['message' => 'Attribute not found.']);
        }

        $duel = Duel::query()->find((int) $data['duel_id']);

        if (!$duel) {
            return $this->error(404, ['message' => 'Duel not found.']);
        }

        $reqA = (int) $duel->player_a_id;
        $reqB = (int) $duel->player_b_id;
        $winnerId = (int) $data['winner_id'];

        if ($winnerId !== $reqA && $winnerId !== $reqB) {
            return $this->error(422, ['message' => 'winner_id must be one of the duel players.']);
        }

        $playerA = min($reqA, $reqB);
        $playerB = max($reqA, $reqB);

        $players = Player::query()
            ->select('id', 'position_id', 'fd_position_id', 'manual_position_id')
            ->with([
                'positionRef:id,short_label',
                'fdPositionRef:id,short_label,key,label',
                'manualPositionRef:id,short_label,key,label',
            ])
            ->whereIn('id', [$playerA, $playerB])
            ->get()
            ->keyBy('id');

        if (!isset($players[$playerA]) || !isset($players[$playerB])) {
            return $this->error(404, ['message' => 'Player not found.']);
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

        $loserId = $winnerId === $reqA ? $reqB : $reqA;

        $vote = null;
        $afterA = $beforeA;
        $afterB = $beforeB;

        $currentUserId = auth()->id();
        $isAuthed = $currentUserId !== null;

        $weights = $this->voteWeightResolver->resolve(
            isAnonymous: ! $isAuthed,
            influenceProfile: $request->user()?->getAttribute('influence_profile'),
        );

        $ratingWeight = $weights->ratingWeight;
        $confidenceWeight = $weights->confidenceWeight;

        $anonId = trim((string) $request->header('X-Zcout-Anon'));

        $lockKeys = [];
        if ($anonId !== '') {
            $lockKeys[] = $anonId;
        }
        if ($isAuthed) {
            $lockKeys[] = 'user:' . $currentUserId;
        }

        $lockKey = $anonId !== '' ? $anonId : ($isAuthed ? ('user:' . $currentUserId) : null);

        if (!$lockKey) {
            return $this->error(400, ['message' => 'Missing voter id.']);
        }

        $voterHash = hash_hmac('sha256', $lockKey, (string) config('app.key'));
        $occurredAt = now();

        try {
            DB::transaction(function () use (
                $attribute,
                $duel,
                $playerA,
                $playerB,
                $winnerId,
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
                $occurredAt
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
                $vote->weight_version = self::WEIGHT_VERSION;
                $vote->reputation_at_vote = null;
                $vote->risk_score_at_vote = null;
                $vote->value = null;
                $vote->pre_rating_a = number_format($beforeA, 3, '.', '');
                $vote->pre_rating_b = number_format($beforeB, 3, '.', '');
                $vote->created_at = $occurredAt;
                $vote->save();

                VoteWeightLog::create([
                    'vote_id' => $vote->id,
                    'weight_version' => self::WEIGHT_VERSION,
                    'rating_algorithm_version' => self::RATING_ALGORITHM_VERSION,
                    'base_duel_weight' => 1.0,
                    'rating_weight_applied' => $ratingWeight,
                    'confidence_weight_applied' => $confidenceWeight,
                ]);

                $applyResult = $this->applyVoteEventToRatingsAction->executeDuel(
                    attributeId: $attribute->id,
                    winnerId: $winnerId,
                    loserId: $loserId,
                    ratingWeight: $ratingWeight,
                    confidenceWeight: $confidenceWeight,
                    occurredAt: $occurredAt,
                );

                if ($winnerId === $playerA) {
                    $afterA = (float) $applyResult['winner']['post_rating'];
                    $afterB = (float) $applyResult['loser']['post_rating'];
                } else {
                    $afterA = (float) $applyResult['loser']['post_rating'];
                    $afterB = (float) $applyResult['winner']['post_rating'];
                }

                $vote->post_rating_a = number_format($afterA, 3, '.', '');
                $vote->post_rating_b = number_format($afterB, 3, '.', '');
                $vote->save();
            });

            $this->dispatchPostVoteEvents($vote);

            DB::table('voter_duel_locks')->whereIn('voter_hash', $lockKeys)->delete();

            return [
                'ok' => true,
                'status' => 200,
                'body' => $this->buildSuccessPayload(
                    vote: $vote,
                    duel: $duel,
                    attribute: $attribute,
                    playerA: $playerA,
                    playerB: $playerB,
                    reqA: $reqA,
                    reqB: $reqB,
                    beforeA: $beforeA,
                    beforeB: $beforeB,
                ),
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->handleDuplicateVoteException(
                $e,
                $lockKeys,
                $duel,
                $attribute,
                $playerA,
                $playerB,
                $winnerId,
                $currentUserId,
                $voterHash,
            );
        }
    }

    private function dispatchPostVoteEvents(?Vote $vote): void
    {
        if ($vote === null) {
            return;
        }

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
    }

    private function buildSuccessPayload(
        ?Vote $vote,
        Duel $duel,
        Attribute $attribute,
        int $playerA,
        int $playerB,
        int $reqA,
        int $reqB,
        float $beforeA,
        float $beforeB,
    ): array {
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

            $badgeData = $this->attributeRankingService->getBadgeData(
                $attribute->key,
                (int) $pid,
            );

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
                'attribute_rank' => $badgeData['rank'],
                'is_top_ten' => $badgeData['is_top_ten'],
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

        return [
            'vote_id' => $vote?->id,
            'duel_id' => $duel->id,
            'attribute_id' => $attribute->id,
            'players' => $playersPayload,
            'popularity' => [
                'player_a_id' => $reqA,
                'player_b_id' => $reqB,
                'votes_a' => $votesA,
                'votes_b' => $votesB,
                'votes_total' => $votesA + $votesB,
            ],
        ];
    }

    private function handleDuplicateVoteException(
        \Illuminate\Database\QueryException $e,
        array $lockKeys,
        Duel $duel,
        Attribute $attribute,
        int $playerA,
        int $playerB,
        int $winnerId,
        ?int $currentUserId,
        string $voterHash,
    ): array {
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

            return $this->error(409, ['message' => 'You already voted on this duel.']);
        }

        throw $e;
    }

    private function error(int $status, array $body): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'body' => $body,
        ];
    }
}
