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
            'user_type' => $user->type,
            'source' => $opportunity->source,
            'opportunity_type' => $opportunity->type,
            'decision_type' => $decision->type,
            'payload' => $decision->payload,
            'opportunity_payload' => $opportunity->payload,
            'step' => $context->currentStep,
        ];
    }

    public function items(): array
    {
        return $this->items;
    }

    public function summary(): array
    {
        $decisionCounts = [];
        $attributeCounts = [];

        foreach ($this->items as $item) {
            $decisionType = $item['decision_type'];
            $attributeKey = $item['opportunity_payload']['attribute'] ?? 'unknown';

            $decisionCounts[$decisionType] = ($decisionCounts[$decisionType] ?? 0) + 1;
            $attributeCounts[$attributeKey] = ($attributeCounts[$attributeKey] ?? 0) + 1;
        }

        ksort($decisionCounts);
        ksort($attributeCounts);

        return [
            'total_events' => count($this->items),
            'decision_counts' => $decisionCounts,
            'attribute_counts' => $attributeCounts,
        ];
    }
}
