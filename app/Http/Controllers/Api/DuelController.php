<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Actions\PlanNextDuelForVoterAction;
use App\Actions\BuildNextDuelPayloadAction;
use App\Actions\ResolveVoterContextAction;
use App\Actions\ResumeLockedDuelAction;
use App\Actions\LoadVoterDuelStateAction;
use App\Actions\SkipDuelForVoterAction;

class DuelController extends Controller
{
    private PlanNextDuelForVoterAction $planNextDuelForVoterAction;
    private BuildNextDuelPayloadAction $buildNextDuelPayloadAction;
    private ResolveVoterContextAction $resolveVoterContextAction;
    private ResumeLockedDuelAction $resumeLockedDuelAction;
    private LoadVoterDuelStateAction $loadVoterDuelStateAction;
    private SkipDuelForVoterAction $skipDuelForVoterAction;

    public function __construct(
        PlanNextDuelForVoterAction $planNextDuelForVoterAction,
        BuildNextDuelPayloadAction $buildNextDuelPayloadAction,
        ResolveVoterContextAction $resolveVoterContextAction,
        ResumeLockedDuelAction $resumeLockedDuelAction,
        LoadVoterDuelStateAction $loadVoterDuelStateAction,
        SkipDuelForVoterAction $skipDuelForVoterAction
    ) {
        $this->planNextDuelForVoterAction = $planNextDuelForVoterAction;
        $this->buildNextDuelPayloadAction = $buildNextDuelPayloadAction;
        $this->resolveVoterContextAction = $resolveVoterContextAction;
        $this->resumeLockedDuelAction = $resumeLockedDuelAction;
        $this->loadVoterDuelStateAction = $loadVoterDuelStateAction;
        $this->skipDuelForVoterAction = $skipDuelForVoterAction;
    }

    public function next()
    {
        $voter = $this->resolveVoterContextAction->handle();

        if (($voter['status'] ?? 'failed') !== 'ok') {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $voterHash = $voter['voter_hash'];
        $voteVoterHash = $voter['vote_voter_hash'];

        $resumedLocked = $this->resumeLockedDuelAction->handle([
            'voter_hash' => $voterHash,
            'vote_voter_hash' => $voteVoterHash,
        ]);

        if (($resumedLocked['status'] ?? 'failed') === 'ok') {
            return response()->json($resumedLocked['payload']);
        }

        if (($resumedLocked['status'] ?? 'failed') === 'failed') {
            return response()->json([
                'error' => 'Failed to resume locked duel',
            ], 422);
        }

        $voterState = $this->loadVoterDuelStateAction->handle([
            'voter_hash' => $voterHash,
            'vote_voter_hash' => $voteVoterHash,
        ]);

        if (($voterState['status'] ?? 'failed') !== 'ok') {
            return response()->json([
                'error' => 'Failed to load voter duel state',
            ], 422);
        }

        $skipped = $voterState['skipped'] ?? [];
        $voted = $voterState['voted'] ?? [];

        $planned = $this->planNextDuelForVoterAction->handle([
            'cfg' => config('zcout_matchmaking', []),
            'skipped' => $skipped,
            'voted' => $voted,
            'voter_hash' => $voterHash,
            'requested_attribute' => request('attribute'),
            'debug' => (string)request('debug') === '1',
            'max_attempts' => 12,
        ]);

        if (($planned['status'] ?? 'failed') !== 'ok') {
            return $this->nextFailureResponse($planned);
        }

        $payload = $this->buildNextDuelPayloadAction->handle([
            'attribute' => $planned['attribute'] ?? null,
            'duel' => $planned['duel'] ?? null,
            'players' => $planned['players'] ?? null,
            'matchmaking' => $planned['matchmaking'] ?? [],
            'debug' => $planned['debug'] ?? null,
        ]);

        return response()->json($payload);
    }

    public function skip()
    {
        $voter = $this->resolveVoterContextAction->handle();

        if (($voter['status'] ?? 'failed') !== 'ok') {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $voterHash = $voter['voter_hash'];

        $duelId = (int)request('duel_id');
        if ($duelId <= 0) {
            return response()->json(['error' => 'Missing duel_id'], 422);
        }

        $skipped = $this->skipDuelForVoterAction->handle([
            'voter_hash' => $voterHash,
            'duel_id' => $duelId,
            'user_id' => auth()->id(),
        ]);

        if (($skipped['status'] ?? 'failed') !== 'ok') {
            return response()->json([
                'error' => 'Failed to skip duel',
                'reason' => $skipped['reason'] ?? 'failed_to_skip_duel',
            ], 422);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    private function nextFailureResponse(array $planned)
    {
        $reason = $planned['failure_reason'] ?? 'failed_to_plan_next_duel';

        if ($reason === 'unknown_attribute') {
            return response()->json(['error' => 'Unknown attribute'], 422);
        }

        if ($reason === 'no_unskipped_duel_available') {
            return response()->json(['error' => 'No unskipped duel available'], 422);
        }

        if ($reason === 'failed_to_pick_duel_pair') {
            return response()->json(['error' => 'Failed to pick duel pair'], 422);
        }

        if (in_array($reason, ['players_not_found', 'missing_attribute', 'missing_picked_players', 'invalid_picked_players'], true)) {
            return response()->json([
                'error' => 'Failed to materialize duel',
                'reason' => $reason,
            ], 422);
        }

        return response()->json([
            'error' => 'Failed to reserve duel',
            'reason' => $reason,
        ], 422);
    }
}
