<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class GenerateSimulatedDuelOpportunity
{
    public function handle(
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionOpportunity {
        $pairs = [
            ['player_a_id' => 1, 'player_b_id' => 2],
            ['player_a_id' => 3, 'player_b_id' => 4],
            ['player_a_id' => 5, 'player_b_id' => 6],
            ['player_a_id' => 7, 'player_b_id' => 8],
        ];

        $attributes = [
            'pace',
            'dribbling',
            'passing',
            'finishing',
        ];

        $seedBase = $context->runId . '|' . $context->currentStep . '|' . $user->id;

        $pairIndex = abs(crc32($seedBase . '|pair')) % count($pairs);
        $attributeIndex = abs(crc32($seedBase . '|attribute')) % count($attributes);

        $pair = $pairs[$pairIndex];
        $attribute = $attributes[$attributeIndex];

        return new InteractionOpportunity(
            source: 'duel',
            type: 'pair',
            payload: [
                'player_a_id' => $pair['player_a_id'],
                'player_b_id' => $pair['player_b_id'],
                'attribute' => $attribute,
            ],
        );
    }
}
