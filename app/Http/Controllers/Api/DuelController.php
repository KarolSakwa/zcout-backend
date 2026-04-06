<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\ResolveVoterContextAction;
use App\Actions\SkipDuelForVoterAction;
use App\Actions\HandleNextDuelRequestAction;

class DuelController extends Controller
{
    private ResolveVoterContextAction $resolveVoterContextAction;
    private SkipDuelForVoterAction $skipDuelForVoterAction;
    private HandleNextDuelRequestAction $handleNextDuelRequestAction;

    public function __construct(
        ResolveVoterContextAction $resolveVoterContextAction,
        SkipDuelForVoterAction $skipDuelForVoterAction,
        HandleNextDuelRequestAction $handleNextDuelRequestAction
    ) {
        $this->resolveVoterContextAction = $resolveVoterContextAction;
        $this->skipDuelForVoterAction = $skipDuelForVoterAction;
        $this->handleNextDuelRequestAction = $handleNextDuelRequestAction;
    }

    public function next()
    {
        $voter = $this->resolveVoterContextAction->handle();

        if (($voter['status'] ?? 'failed') !== 'ok') {
            return response()->json(['error' => 'Missing voter id'], 400);
        }

        $result = $this->handleNextDuelRequestAction->handle([
            'cfg' => config('zcout_matchmaking', []),
            'requested_attribute' => request('attribute'),
            'debug' => (string) request('debug') === '1',
            'max_attempts' => 12,
            'voter_hash' => $voter['voter_hash'],
            'vote_voter_hash' => $voter['vote_voter_hash'],
        ]);

        if (($result['status'] ?? 'error') !== 'ok') {
            return response()->json(
                $result['body'] ?? ['error' => 'Failed to handle next duel request'],
                (int) ($result['http_status'] ?? 422),
            );
        }

        return response()->json($result['payload'] ?? []);
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
}
