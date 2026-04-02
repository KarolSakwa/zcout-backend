<?php

namespace App\Simulation\Outputs;

use App\Simulation\Contracts\SimulationDecisionProcessor;
use App\Simulation\Contracts\SimulationOutput;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class MaterializingSimulationOutput implements SimulationOutput
{
    public function __construct(
        private readonly SimulationDecisionProcessor $processor,
    ) {
    }

    public function handleDecision(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): int {
        return $this->processor->process($user, $opportunity, $decision, $context);
    }
}
