<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class SimulateDuelDecision
{
    public function handle(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision {
        $base = implode('|', [
            $context->runId,
            $context->currentStep,
            $user->id,
            $user->type,
            $opportunity->payload['player_a_id'] ?? 'a',
            $opportunity->payload['player_b_id'] ?? 'b',
            $opportunity->payload['attribute'] ?? 'attr',
        ]);

        $skipRoll = abs(crc32($base . '|skip')) % 1000;
        $winnerRoll = abs(crc32($base . '|winner')) % 1000;

        $skipThreshold = match ($user->type) {
            'expert' => 20,
            'casual' => 120,
            default => 80,
        };

        if ($skipRoll < $skipThreshold) {
            return new InteractionDecision(
                source: 'duel',
                type: 'skip',
                payload: [],
            );
        }

        $decisionType = $winnerRoll < 500 ? 'vote_left' : 'vote_right';

        return new InteractionDecision(
            source: 'duel',
            type: $decisionType,
            payload: [
                'winner' => $decisionType === 'vote_left' ? 'left' : 'right',
            ],
        );
    }
}
