<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\Duels\ResolveVoterContextAction;
use App\Actions\Duels\HandleNextDuelRequestAction;
use App\Actions\Duels\HandleSkipDuelRequestAction;

class DuelController extends Controller
{
    private ResolveVoterContextAction $resolveVoterContextAction;
    private HandleNextDuelRequestAction $handleNextDuelRequestAction;
    private HandleSkipDuelRequestAction $handleSkipDuelRequestAction;

    public function __construct(
        ResolveVoterContextAction $resolveVoterContextAction,
        HandleNextDuelRequestAction $handleNextDuelRequestAction,
        HandleSkipDuelRequestAction $handleSkipDuelRequestAction
    ) {
        $this->resolveVoterContextAction = $resolveVoterContextAction;
        $this->handleNextDuelRequestAction = $handleNextDuelRequestAction;
        $this->handleSkipDuelRequestAction = $handleSkipDuelRequestAction;
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
            'requested_intent' => request('intent'),
            'requested_tier' => request('tier'),
            'requested_position_profile' => request('position_profile'),
            'requested_gap_profile' => request('gap_profile'),
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

        $result = $this->handleSkipDuelRequestAction->handle([
            'voter_hash' => $voter['voter_hash'],
            'duel_id' => (int) request('duel_id'),
            'user_id' => auth()->id(),
        ]);

        if (($result['status'] ?? 'error') !== 'ok') {
            return response()->json(
                $result['body'] ?? ['error' => 'Failed to handle skip duel request'],
                (int) ($result['http_status'] ?? 422),
            );
        }

        return response()->json($result['payload'] ?? ['ok' => true]);
    }
}
