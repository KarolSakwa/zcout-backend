<?php

namespace App\Simulation\Outputs;

use App\Simulation\Contracts\SimulationOutput;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class CollectingSimulationOutput implements SimulationOutput
{
    private array $items = [];

    public function handleDecision(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): void {
        $this->items[] = [
            'user_id' => $user->id,
            'source' => $opportunity->source,
            'opportunity_type' => $opportunity->type,
            'decision_type' => $decision->type,
            'payload' => $decision->payload,
        ];
    }

    public function items(): array
    {
        return $this->items;
    }
}
