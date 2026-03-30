<?php

namespace App\Simulation\Processors;

use App\Simulation\Contracts\SimulationDecisionProcessor;
use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;
use RuntimeException;

final class RoutingSimulationDecisionProcessor implements SimulationDecisionProcessor
{
    /**
     * @param array<string, SimulationDecisionProcessor> $processors
     */
    public function __construct(
        private readonly array $processors,
    ) {
    }

    public function process(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): void {
        $processor = $this->processors[$opportunity->source] ?? null;

        if ($processor === null) {
            throw new RuntimeException("No processor registered for source [{$opportunity->source}].");
        }

        $processor->process($user, $opportunity, $decision, $context);
    }
}
