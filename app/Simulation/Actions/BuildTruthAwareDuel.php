<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\TruthAwareDuel;
use App\Simulation\Services\SimulationTruthReader;
use App\Simulation\SimulationContext;
use RuntimeException;

final class BuildTruthAwareDuel
{
    public function __construct(
        private readonly SimulationTruthReader $truthReader = new SimulationTruthReader(),
    ) {
    }

    public function handle(
        InteractionOpportunity $opportunity,
        SimulationContext $context
    ): TruthAwareDuel {
        $playerAId = (int) ($opportunity->payload['player_a_id'] ?? 0);
        $playerBId = (int) ($opportunity->payload['player_b_id'] ?? 0);
        $attributeKey = (string) ($opportunity->payload['attribute'] ?? '');

        if ($playerAId <= 0 || $playerBId <= 0 || $attributeKey === '') {
            throw new RuntimeException('Cannot build TruthAwareDuel from incomplete opportunity payload.');
        }

        return new TruthAwareDuel(
            playerAId: $playerAId,
            playerBId: $playerBId,
            attributeKey: $attributeKey,
            truthRatingA: $this->truthReader->getRating($context->runId, $playerAId, $attributeKey),
            truthRatingB: $this->truthReader->getRating($context->runId, $playerBId, $attributeKey),
        );
    }
}
