<?php

namespace App\Simulation\Actions;

use App\Actions\Ratings\ApplyVoteEventToRatingsAction;
use App\Http\Controllers\Api\VoteController;
use App\Simulation\Data\SimulatedDuelVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SubmitSimulatedDuelVoteToApp
{
    public function handle(SimulatedDuelVote $vote): int
    {
        if ($vote->decisionType !== 'vote' || $vote->winnerPlayerId === null) {
            return 204;
        }

        $body = json_encode([
            'duel_id' => $vote->duelId,
            'attribute_key' => $vote->attributeKey,
            'player_a_id' => $vote->playerAId,
            'player_b_id' => $vote->playerBId,
            'winner_id' => $vote->winnerPlayerId,
        ]);

        $request = Request::create('/api/votes', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        if ($vote->isLogged && $vote->appUserId !== null) {
            Auth::onceUsingId($vote->appUserId);
        } else {
            $request->headers->set('X-Zcout-Anon', 'sim:' . $vote->simulatedUserId);
        }

        app()->instance('request', $request);

        $response = app(VoteController::class)->store(
            $request,
            app(ApplyVoteEventToRatingsAction::class)
        );

        Auth::forgetGuards();

        return $response->getStatusCode();
    }
}
