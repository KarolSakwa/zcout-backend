<?php

namespace App\Simulation\Actions;

use App\Http\Controllers\Api\VoteController;
use App\Services\RatingService;
use App\Simulation\Data\SimulatedDuelVote;
use Illuminate\Http\Request;

final class SubmitSimulatedDuelVoteToApp
{
    public function __construct(
        private readonly VoteController $voteController = new VoteController(),
        private readonly RatingService $ratingService = new RatingService(),
    ) {
    }

    public function handle(SimulatedDuelVote $vote): void
    {
        if ($vote->decisionType !== 'vote' || $vote->winnerPlayerId === null) {
            return;
        }

        $body = json_encode([
            'attribute_key' => $vote->attributeKey,
            'player_a_id' => $vote->playerAId,
            'player_b_id' => $vote->playerBId,
            'winner_id' => $vote->winnerPlayerId,
        ]);

        $request = Request::create('/api/votes', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Zcout-Anon', 'sim:' . $vote->simulatedUserId);

        app()->instance('request', $request);

        $this->voteController->store($request, $this->ratingService);
    }
}
