<?php

namespace App\Simulation\Sources;

use App\Simulation\Actions\GenerateSimulatedDuelOpportunity;
use App\Simulation\Actions\SimulateDuelDecision;
use App\Simulation\Contracts\InteractionSource;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class DuelInteractionSource implements InteractionSource
{
    public function __construct(
        private readonly GenerateSimulatedDuelOpportunity $opportunityGenerator,
        private readonly SimulateDuelDecision $decisionSimulator,
    ) {
    }

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
        return $this->opportunityGenerator->handle($user, $context);
    }

    public function simulateDecision(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision {
        return $this->decisionSimulator->handle($opportunity, $user, $context);
    }
}
