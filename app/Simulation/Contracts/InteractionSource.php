<?php

namespace App\Simulation\Contracts;

use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

interface InteractionSource
{
    public function key(): string;

    public function canGenerateFor(SimulatedUser $user, SimulationContext $context): bool;

    public function generateOpportunity(
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionOpportunity;

    public function simulateDecision(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision;
}
