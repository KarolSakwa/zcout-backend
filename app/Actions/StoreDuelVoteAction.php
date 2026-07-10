<?php

namespace App\Actions;

use App\Data\ActionFailure;
use App\Data\DuelVote\DuelVoteContext;
use App\Data\DuelVote\PersistedDuelVoteResult;
use App\Data\DuelVote\VoterIdentity;
use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Models\PlayerAttributeRating;
use App\Models\Vote;
use App\Services\Ranking\AttributeRankingService;
use App\Support\Live\RecentVoteItem;
use App\Support\VoteWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class StoreDuelVoteAction
{
    public function __construct(
        private readonly BuildDuelVoteContextAction $buildDuelVoteContextAction,
        private readonly ResolveVoterIdentityAction $resolveVoterIdentityAction,
        private readonly PersistDuelVoteAction $persistDuelVoteAction,
        private readonly AttributeRankingService $attributeRankingService,
        private readonly VoteWeightResolver $voteWeightResolver,
    ) {
    }

    public function execute(array $data, Request $request): array
    {
        $context = $this->buildDuelVoteContextAction->execute($data);

        if ($context instanceof ActionFailure) {
            return $this->error(
                $context->status,
                ['message' => $context->message],
            );
        }

        $currentUserId = auth()->id();
        $isAuthed = $currentUserId !== null;

        $weights = $this->voteWeightResolver->resolve(
            isAnonymous: ! $isAuthed,
            influenceProfile: $request->user()?->getAttribute('influence_profile'),
        );

        $ratingWeight = $weights->ratingWeight;
        $confidenceWeight = $weights->confidenceWeight;

        $identity = $this->resolveVoterIdentityAction->execute($request);

        if ($identity instanceof ActionFailure) {
            return $this->error(
                $identity->status,
                ['message' => $identity->message],
            );
        }

        $occurredAt = now();

        try {
            $persisted = $this->persistDuelVoteAction->execute(
                context: $context,
                identity: $identity,
                ratingWeight: $ratingWeight,
                confidenceWeight: $confidenceWeight,
                occurredAt: $occurredAt,
            );

            $this->dispatchPostVoteEvents($persisted->vote);

            DB::table('voter_duel_locks')->whereIn('voter_hash', $identity->lockKeys)->delete();

            return [
                'ok' => true,
                'status' => 200,
                'body' => $this->buildSuccessPayload($context, $persisted),
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->handleDuplicateVoteException($e, $context, $identity);
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
        DuelVoteContext $context,
        PersistedDuelVoteResult $persisted,
    ): array {
        $afterRows = PlayerAttributeRating::query()
            ->where('attribute_id', $context->attribute->id)
            ->whereIn('player_id', [$context->canonicalPlayerAId, $context->canonicalPlayerBId])
            ->get()
            ->keyBy('player_id');

        $playersPayload = [];
        foreach ([$context->canonicalPlayerAId, $context->canonicalPlayerBId] as $playerId) {
            $before = $playerId === $context->canonicalPlayerAId
                ? $context->ratingBeforeA
                : $context->ratingBeforeB;
            $afterRow = $afterRows[$playerId] ?? null;
            $after = (float) ($afterRow?->rating ?? $before);

            $badgeData = $this->attributeRankingService->getBadgeData(
                $context->attribute->key,
                (int) $playerId,
            );

            $playersPayload[] = [
                'id' => (int) $playerId,
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

        $popularity = DB::table('votes')
            ->select('winner_id', DB::raw('COUNT(*) as c'))
            ->where('duel_id', $context->duel->id)
            ->whereIn('winner_id', [$context->duelPlayerAId, $context->duelPlayerBId])
            ->groupBy('winner_id')
            ->pluck('c', 'winner_id');

        $votesForDuelPlayerA = (int) ($popularity[$context->duelPlayerAId] ?? 0);
        $votesForDuelPlayerB = (int) ($popularity[$context->duelPlayerBId] ?? 0);

        return [
            'vote_id' => $persisted->vote?->id,
            'duel_id' => $context->duel->id,
            'attribute_id' => $context->attribute->id,
            'players' => $playersPayload,
            'popularity' => [
                'player_a_id' => $context->duelPlayerAId,
                'player_b_id' => $context->duelPlayerBId,
                'votes_a' => $votesForDuelPlayerA,
                'votes_b' => $votesForDuelPlayerB,
                'votes_total' => $votesForDuelPlayerA + $votesForDuelPlayerB,
            ],
        ];
    }

    private function handleDuplicateVoteException(
        \Illuminate\Database\QueryException $e,
        DuelVoteContext $context,
        VoterIdentity $identity,
    ): array {
        $msg = (string) $e->getMessage();
        $code = (string) $e->getCode();

        if ($code === '23505' || stripos($msg, 'votes_unique_duel_voterhash') !== false) {
            DB::table('voter_duel_locks')->whereIn('voter_hash', $identity->lockKeys)->delete();

            Log::warning('vote.duel_duplicate_vote', [
                'duel_id' => $context->duel->id ?? null,
                'attribute_id' => $context->attribute->id ?? null,
                'player_a_id' => $context->canonicalPlayerAId ?? null,
                'player_b_id' => $context->canonicalPlayerBId ?? null,
                'winner_id' => $context->winnerId ?? null,
                'user_id' => $identity->userId,
                'voter_hash' => $identity->voterHash ?? null,
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
