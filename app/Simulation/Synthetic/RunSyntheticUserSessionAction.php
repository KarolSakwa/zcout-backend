<?php

namespace App\Simulation\Synthetic;

use App\Actions\HandleNextDuelRequestAction;
use App\Actions\HandleSkipDuelRequestAction;
use App\Actions\ResolveVoterContextAction;
use App\Actions\StoreDuelVoteAction;
use App\Models\User;
use App\Simulation\Decision\DuelDecisionPolicy;
use App\Simulation\Decision\SyntheticSessionDecisionSeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunSyntheticUserSessionAction
{
    public function __construct(
        private readonly HandleNextDuelRequestAction $handleNextDuelRequestAction,
        private readonly HandleSkipDuelRequestAction $handleSkipDuelRequestAction,
        private readonly StoreDuelVoteAction $storeDuelVoteAction,
        private readonly ResolveVoterContextAction $resolveVoterContextAction,
        private readonly DuelDecisionPolicy $decisionPolicy = new DuelDecisionPolicy(),
    ) {
    }

    /**
     * @param callable(SyntheticSessionActionResult): void|null $onAction
     */
    public function execute(
        User $user,
        string $profile,
        int $actions,
        string $sessionId,
        ?callable $onAction = null,
    ): SyntheticSessionSummary {
        $votes = 0;
        $skips = 0;
        $failures = 0;
        $actionIndex = 0;

        try {
            $this->bindAuthenticatedRequest($user);

            for ($actionIndex = 1; $actionIndex <= $actions; $actionIndex++) {
                $result = $this->runAction($user, $profile, $actions, $sessionId, $actionIndex);

                if ($result->status === 'ok' && $result->decision === 'vote') {
                    $votes++;
                } elseif ($result->status === 'ok' && $result->decision === 'skip') {
                    $skips++;
                } elseif ($result->status === 'failure') {
                    $failures++;
                }

                if ($onAction !== null) {
                    $onAction($result);
                }
            }

            return new SyntheticSessionSummary(
                votes: $votes,
                skips: $skips,
                failures: $failures,
                completed: true,
            );
        } catch (Throwable $exception) {
            Log::error('synthetic.session.unexpected_error', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'action_index' => $actionIndex,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            Auth::logout();
        }
    }

    private function runAction(
        User $user,
        string $profile,
        int $plannedActions,
        string $sessionId,
        int $actionIndex,
    ): SyntheticSessionActionResult {
        $voter = $this->resolveVoterContext();
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

        $decisionSeed = SyntheticSessionDecisionSeed::build(
            userId: (int) $user->id,
            profile: $profile,
            sessionId: $sessionId,
            actionIndex: $actionIndex,
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: $attributeKey,
        );

        $decision = $this->decisionPolicy->decide(
            decisionSeed: $decisionSeed,
            userType: $profile,
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: $attributeKey,
            truthRatingA: $ratingA,
            truthRatingB: $ratingB,
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

    /**
     * @return array{status: string, failure_reason: ?string, anon: ?string, user_id: ?int, voter_hash: ?string, vote_voter_hash: ?string}
     */
    private function resolveVoterContext(): array
    {
        return $this->resolveVoterContextAction->handle();
    }

    private function bindAuthenticatedRequest(User $user): void
    {
        Auth::login($user);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn () => $user);
        app()->instance('request', $request);
    }

    private function bindVoteRequest(User $user): Request
    {
        $request = Request::create('/api/votes', 'POST');
        $request->setUserResolver(static fn () => $user);
        app()->instance('request', $request);

        return $request;
    }
}
