<?php

namespace App\Simulation\Actions;

use App\Http\Controllers\Api\VoteController;
use App\Services\RatingService;
use App\Simulation\Data\SimulatedDuelVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SubmitSimulatedDuelVoteToApp
{
    public function __construct(
        private readonly VoteController $voteController = new VoteController(),
        private readonly RatingService $ratingService = new RatingService(),
    ) {
    }

    public function handle(SimulatedDuelVote $vote): int
    {
        if ($vote->decisionType !== 'vote' || $vote->winnerPlayerId === null) {
            return 204;
        }

        $body = json_encode([
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

        $response = $this->voteController->store($request, $this->ratingService);

        Auth::forgetGuards();

        return $response->getStatusCode();
    }
}
