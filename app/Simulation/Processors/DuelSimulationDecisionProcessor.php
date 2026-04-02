<?php

namespace App\Simulation\Processors;

use App\Simulation\Actions\ProcessSimulatedDuelDecision;
use App\Simulation\Contracts\SimulationDecisionProcessor;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class DuelSimulationDecisionProcessor implements SimulationDecisionProcessor
{
    public function __construct(
        private readonly ProcessSimulatedDuelDecision $action = new ProcessSimulatedDuelDecision(),
    ) {
    }

    public function process(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): int {
        return $this->action->handle($user, $opportunity, $decision, $context);
    }
}
