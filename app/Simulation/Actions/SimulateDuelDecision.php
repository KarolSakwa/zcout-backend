<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\Decision\DuelDecisionPolicy;
use App\Simulation\Decision\SimulationLabDecisionSeed;
use App\Simulation\SimulationContext;

final class SimulateDuelDecision
{
    public function __construct(
        private readonly BuildTruthAwareDuel $truthAwareDuelBuilder = new BuildTruthAwareDuel(),
        private readonly DuelDecisionPolicy $decisionPolicy = new DuelDecisionPolicy(),
    ) {
    }

    public function handle(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision {
        $duel = $this->truthAwareDuelBuilder->handle($opportunity, $context);

        if ($duel->truthRatingA === null || $duel->truthRatingB === null) {
            return new InteractionDecision(
                source: 'duel',
                type: 'skip',
                payload: [],
            );
        }

        $decisionSeed = SimulationLabDecisionSeed::build(
            runId: $context->runId,
            currentStep: $context->currentStep,
            userId: $user->id,
            userType: $user->type,
            playerAId: $duel->playerAId,
            playerBId: $duel->playerBId,
            attributeKey: $duel->attributeKey,
        );

        $result = $this->decisionPolicy->decide(
            decisionSeed: $decisionSeed,
            userType: $user->type,
            playerAId: $duel->playerAId,
            playerBId: $duel->playerBId,
            attributeKey: $duel->attributeKey,
            truthRatingA: $duel->truthRatingA,
            truthRatingB: $duel->truthRatingB,
        );

        if ($result->type === 'skip') {
            return new InteractionDecision(
                source: 'duel',
                type: 'skip',
                payload: [],
            );
        }

        return new InteractionDecision(
            source: 'duel',
            type: 'vote',
            payload: [
                'winner_player_id' => $result->winnerPlayerId,
                'truth_diff' => $result->truthDiff,
            ],
        );
    }
}
