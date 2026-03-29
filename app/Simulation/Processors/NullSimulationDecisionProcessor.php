<?php

namespace App\Simulation\Processors;

use App\Simulation\Contracts\SimulationDecisionProcessor;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class NullSimulationDecisionProcessor implements SimulationDecisionProcessor
{
    public function process(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): void {
    }
}
