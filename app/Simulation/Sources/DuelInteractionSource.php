<?php

namespace App\Simulation\Sources;

use App\Simulation\Contracts\InteractionSource;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class DuelInteractionSource implements InteractionSource
{
    public function key(): string
    {
        return 'duel';
    }

    public function canGenerateFor(SimulatedUser $user, SimulationContext $context): bool
    {
        return true;
    }

    public function generateOpportunity(
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionOpportunity {
        return new InteractionOpportunity(
            source: 'duel',
            type: 'pair',
            payload: [
                'player_a_id' => 1,
                'player_b_id' => 2,
                'attribute' => 'pace',
            ],
        );
    }

    public function simulateDecision(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision {
        return new InteractionDecision(
            source: 'duel',
            type: 'vote_left',
            payload: [
                'winner' => 'left',
            ],
        );
    }
}
