<?php

namespace App\Simulation\Synthetic;

use App\Actions\HandleNextDuelRequestAction;
use App\Actions\HandleSkipDuelRequestAction;
use App\Actions\ResolveVoterContextAction;
use App\Actions\StoreDuelVoteAction;
use App\Models\SyntheticUserProfile;
use App\Models\User;
use App\Simulation\Decision\SyntheticDuelDecisionPolicy;
use App\Simulation\Decision\SyntheticSessionDecisionSeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecuteSyntheticDuelAction
{
    public function __construct(
        private readonly HandleNextDuelRequestAction $handleNextDuelRequestAction,
        private readonly HandleSkipDuelRequestAction $handleSkipDuelRequestAction,
        private readonly StoreDuelVoteAction $storeDuelVoteAction,
        private readonly ResolveVoterContextAction $resolveVoterContextAction,
        private readonly SyntheticDuelDecisionPolicy $decisionPolicy = new SyntheticDuelDecisionPolicy(),
    ) {
    }

    /**
     * Caller must authenticate the synthetic user on the current request before calling.
     */
    public function execute(
        User $user,
        SyntheticUserProfile $profile,
        string $sessionSeed,
        int $actionIndex,
        int $plannedActions,
    ): SyntheticSessionActionResult {
        $voter = $this->resolveVoterContextAction->handle();
        $voterHash = (string) $voter['voter_hash'];

        $nextDuel = $this->handleNextDuelRequestAction->handle([
            'cfg' => config('zcout_matchmaking', []),
            'requested_attribute' => null,
            'requested_intent' => null,
            'requested_tier' => null,
            'requested_position_profile' => null,
            'requested_gap_profile' => null,
            'debug' => false,
            'max_attempts' => 12,
            'voter_hash' => $voterHash,
            'vote_voter_hash' => (string) $voter['vote_voter_hash'],
        ]);

        if (($nextDuel['status'] ?? 'error') !== 'ok') {
            return new SyntheticSessionActionResult(
                actionIndex: $actionIndex,
                plannedActions: $plannedActions,
                duelId: null,
                attributeKey: null,
                playerAId: null,
                playerBId: null,
                decision: null,
                winnerId: null,
                status: 'failure',
                reason: 'no_duel_available',
            );
        }

        $payload = $nextDuel['payload'] ?? [];
        $duelId = (int) ($payload['duel_id'] ?? 0);
        $attributeKey = (string) ($payload['attribute']['key'] ?? '');
        $players = $payload['players'] ?? [];
        $playerAId = (int) ($players[0]['id'] ?? 0);
        $playerBId = (int) ($players[1]['id'] ?? 0);

        if ($duelId <= 0 || $attributeKey === '' || $playerAId <= 0 || $playerBId <= 0) {
            return new SyntheticSessionActionResult(
                actionIndex: $actionIndex,
                plannedActions: $plannedActions,
                duelId: $duelId > 0 ? $duelId : null,
                attributeKey: $attributeKey !== '' ? $attributeKey : null,
                playerAId: $playerAId > 0 ? $playerAId : null,
                playerBId: $playerBId > 0 ? $playerBId : null,
                decision: null,
                winnerId: null,
                status: 'failure',
                reason: 'invalid_duel_payload',
            );
        }

        $ratingA = $this->fetchLiveRating($playerAId, $attributeKey);
        $ratingB = $this->fetchLiveRating($playerBId, $attributeKey);

        if ($ratingA === null || $ratingB === null) {
            $skipResult = $this->performSkip($user, $voterHash, $duelId);

            return new SyntheticSessionActionResult(
                actionIndex: $actionIndex,
                plannedActions: $plannedActions,
                duelId: $duelId,
                attributeKey: $attributeKey,
                playerAId: $playerAId,
                playerBId: $playerBId,
                decision: 'skip',
                winnerId: null,
                status: $skipResult['status'],
                reason: $skipResult['status'] === 'ok' ? 'missing_live_rating' : $skipResult['reason'],
            );
        }

        $decisionProfile = (string) $profile->decision_profile;

        $decisionSeed = SyntheticSessionDecisionSeed::build(
            userId: (int) $user->id,
            profile: $decisionProfile,
            sessionId: $sessionSeed,
            actionIndex: $actionIndex,
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: $attributeKey,
        );

        $decision = $this->decisionPolicy->decide(
            decisionSeed: $decisionSeed,
            playerAId: $playerAId,
            playerBId: $playerBId,
            ratingA: $ratingA,
            ratingB: $ratingB,
            skipProbability: (float) $profile->skip_probability,
            decisionAccuracy: (float) $profile->decision_accuracy,
            noiseLevel: (float) $profile->noise_level,
        );

        if ($decision->type === 'skip') {
            $skipResult = $this->performSkip($user, $voterHash, $duelId);

            return new SyntheticSessionActionResult(
                actionIndex: $actionIndex,
                plannedActions: $plannedActions,
                duelId: $duelId,
                attributeKey: $attributeKey,
                playerAId: $playerAId,
                playerBId: $playerBId,
                decision: 'skip',
                winnerId: null,
                status: $skipResult['status'],
                reason: $skipResult['status'] === 'ok' ? 'policy' : $skipResult['reason'],
            );
        }

        $voteResult = $this->storeDuelVoteAction->execute(
            [
                'duel_id' => $duelId,
                'attribute_key' => $attributeKey,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => (int) $decision->winnerPlayerId,
            ],
            $this->bindVoteRequest($user),
        );

        if (($voteResult['ok'] ?? false) !== true) {
            $statusCode = (int) ($voteResult['status'] ?? 500);
            $reason = match ($statusCode) {
                409 => 'duplicate_vote',
                422 => 'validation_error',
                404 => 'not_found',
                default => 'vote_failed',
            };

            if ($statusCode === 409) {
                $this->performSkip($user, $voterHash, $duelId);
            }

            return new SyntheticSessionActionResult(
                actionIndex: $actionIndex,
                plannedActions: $plannedActions,
                duelId: $duelId,
                attributeKey: $attributeKey,
                playerAId: $playerAId,
                playerBId: $playerBId,
                decision: 'vote',
                winnerId: (int) $decision->winnerPlayerId,
                status: 'failure',
                reason: $reason,
            );
        }

        return new SyntheticSessionActionResult(
            actionIndex: $actionIndex,
            plannedActions: $plannedActions,
            duelId: $duelId,
            attributeKey: $attributeKey,
            playerAId: $playerAId,
            playerBId: $playerBId,
            decision: 'vote',
            winnerId: (int) $decision->winnerPlayerId,
            status: 'ok',
        );
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function performSkip(User $user, string $voterHash, int $duelId): array
    {
        $result = $this->handleSkipDuelRequestAction->handle([
            'voter_hash' => $voterHash,
            'duel_id' => $duelId,
            'user_id' => (int) $user->id,
        ]);

        if (($result['status'] ?? 'error') !== 'ok') {
            return [
                'status' => 'failure',
                'reason' => (string) ($result['body']['reason'] ?? 'skip_failed'),
            ];
        }

        return [
            'status' => 'ok',
            'reason' => 'policy',
        ];
    }

    private function fetchLiveRating(int $playerId, string $attributeKey): ?float
    {
        $rating = DB::table('player_attribute_ratings as par')
            ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
            ->where('par.player_id', $playerId)
            ->where('a.key', $attributeKey)
            ->value('par.rating');

        return $rating !== null ? (float) $rating : null;
    }

    private function bindVoteRequest(User $user): Request
    {
        $request = Request::create('/api/votes', 'POST');
        $request->setUserResolver(static fn () => $user);
        app()->instance('request', $request);

        return $request;
    }
}
