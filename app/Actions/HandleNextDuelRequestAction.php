<?php

namespace App\Actions;

final class HandleNextDuelRequestAction
{
    public function __construct(
        private ResumeLockedDuelAction $resumeLockedDuelAction,
        private LoadVoterDuelStateAction $loadVoterDuelStateAction,
        private PlanNextDuelForVoterAction $planNextDuelForVoterAction,
        private BuildNextDuelPayloadAction $buildNextDuelPayloadAction
    ) {
    }

    public function handle(array $context): array
    {
        $cfg = $context['cfg'] ?? config('zcout_matchmaking', []);
        $requestedAttribute = $context['requested_attribute'] ?? null;
        $requestedIntent = $context['requested_intent'] ?? null;
        $requestedTier = $context['requested_tier'] ?? null;
        $requestedPositionProfile = $context['requested_position_profile'] ?? null;
        $requestedGapProfile = $context['requested_gap_profile'] ?? null;
        $debug = (bool) ($context['debug'] ?? false);
        $maxAttempts = (int) ($context['max_attempts'] ?? 12);

        $voterHash = (string) ($context['voter_hash'] ?? '');
        $voteVoterHash = (string) ($context['vote_voter_hash'] ?? '');

        if ($voterHash === '' || $voteVoterHash === '') {
            return [
                'status' => 'error',
                'http_status' => 400,
                'body' => ['error' => 'Missing voter id'],
            ];
        }

        $resumedLocked = $this->resumeLockedDuelAction->handle([
            'voter_hash' => $voterHash,
            'vote_voter_hash' => $voteVoterHash,
        ]);

        if (($resumedLocked['status'] ?? 'failed') === 'ok') {
            return [
                'status' => 'ok',
                'payload' => $resumedLocked['payload'],
            ];
        }

        if (($resumedLocked['status'] ?? 'failed') === 'failed') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => [
                    'error' => 'Failed to resume locked duel',
                ],
            ];
        }

        $voterState = $this->loadVoterDuelStateAction->handle([
            'voter_hash' => $voterHash,
            'vote_voter_hash' => $voteVoterHash,
        ]);

        if (($voterState['status'] ?? 'failed') !== 'ok') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => [
                    'error' => 'Failed to load voter duel state',
                ],
            ];
        }

        $planned = $this->planNextDuelForVoterAction->handle([
            'cfg' => $cfg,
            'skipped' => $voterState['skipped'] ?? [],
            'voted' => $voterState['voted'] ?? [],
            'voter_hash' => $voterHash,
            'requested_attribute' => $requestedAttribute,
            'requested_intent' => $requestedIntent,
            'requested_tier' => $requestedTier,
            'requested_position_profile' => $requestedPositionProfile,
            'requested_gap_profile' => $requestedGapProfile,
            'debug' => $debug,
            'max_attempts' => $maxAttempts,
        ]);

        if (($planned['status'] ?? 'failed') !== 'ok') {
            return $this->planningFailureResponse($planned);
        }

        $payload = $this->buildNextDuelPayloadAction->handle([
            'attribute' => $planned['attribute'] ?? null,
            'duel' => $planned['duel'] ?? null,
            'players' => $planned['players'] ?? null,
            'matchmaking' => $planned['matchmaking'] ?? [],
            'debug' => $planned['debug'] ?? null,
        ]);

        return [
            'status' => 'ok',
            'payload' => $payload,
        ];
    }

    private function planningFailureResponse(array $planned): array
    {
        $reason = $planned['failure_reason'] ?? 'failed_to_plan_next_duel';

        if ($reason === 'unknown_attribute') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => ['error' => 'Unknown attribute'],
            ];
        }

        if ($reason === 'no_unskipped_duel_available') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => ['error' => 'No unskipped duel available'],
            ];
        }

        if ($reason === 'failed_to_pick_duel_pair') {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => ['error' => 'Failed to pick duel pair'],
            ];
        }

        if (in_array($reason, ['players_not_found', 'missing_attribute', 'missing_picked_players', 'invalid_picked_players'], true)) {
            return [
                'status' => 'error',
                'http_status' => 422,
                'body' => [
                    'error' => 'Failed to materialize duel',
                    'reason' => $reason,
                ],
            ];
        }

        return [
            'status' => 'error',
            'http_status' => 422,
            'body' => [
                'error' => 'Failed to reserve duel',
                'reason' => $reason,
            ],
        ];
    }
}
