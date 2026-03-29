<?php

namespace App\Simulation\Outputs;

use App\Simulation\Contracts\SimulationOutput;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class NullSimulationOutput implements SimulationOutput
{
    public function handleDecision(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): void {
    }
}
