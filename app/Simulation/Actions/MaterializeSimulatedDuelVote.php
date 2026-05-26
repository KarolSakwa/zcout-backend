<?php

namespace App\Simulation\Actions;

use App\Models\SimulationRunEvent;
use App\Simulation\Data\SimulatedDuelVote;
use App\Simulation\SimulationContext;

final class MaterializeSimulatedDuelVote
{
    public function __construct(
        private readonly SubmitSimulatedDuelVoteToApp $appVoteSubmitter = new SubmitSimulatedDuelVoteToApp(),
    ) {
    }

    public function handle(SimulatedDuelVote $vote, SimulationContext $context): int
    {
        $statusCode = $this->appVoteSubmitter->handle($vote);

        if ($statusCode === 409) {
            return 409;
        }

        $nextSequence = (int) SimulationRunEvent::query()
                ->where('simulation_run_id', $context->runId)
                ->max('sequence') + 1;

        SimulationRunEvent::query()->create([
            'simulation_run_id' => $context->runId,
            'sequence' => $nextSequence,
            'source' => 'duel',
            'event_type' => $vote->decisionType,
            'simulated_user_id' => $vote->simulatedUserId,
            'is_logged' => $vote->isLogged,
            'payload' => [
                'player_a_id' => $vote->playerAId,
                'player_b_id' => $vote->playerBId,
                'player_a_name' => $vote->playerAName,
                'player_b_name' => $vote->playerBName,
                'attribute_key' => $vote->attributeKey,
                'step' => $vote->step,
                'winner_player_id' => $vote->winnerPlayerId,
            ],
        ]);

        return $statusCode;
    }
}
