<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\Services\SimulationTruthPoolReader;
use App\Simulation\SimulationContext;

final class GenerateSimulatedDuelOpportunity
{
    public function __construct(
        private readonly FetchNextDuelPayload $duelPayloadFetcher,
        private readonly SimulationTruthPoolReader $truthPoolReader,
    ) {
    }

    public function handle(
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionOpportunity {
        $attributeKeys = $this->truthPoolReader->getAttributeKeysForRun($context->runId);

        if ($attributeKeys === []) {
            return null;
        }

        $seed = (int) ($context->config['seed'] ?? 12345);

        $base = implode('|', [
            $seed,
            $context->runId,
            $context->currentStep,
            $user->id,
        ]);

        $attributeKey = $attributeKeys[abs(crc32($base . '|attribute')) % count($attributeKeys)];

        $payload = $this->duelPayloadFetcher->handle(
            $attributeKey,
            $user->isLogged ? null : 'sim:' . $user->id,
            $user->isLogged ? $user->appUserId : null,
        );

        if (! is_array($payload)) {
            return null;
        }

        $players = $payload['players'] ?? null;
        $attribute = $payload['attribute'] ?? null;

        if (! is_array($players) || count($players) < 2 || ! is_array($attribute)) {
            return null;
        }

        $playerAId = (int) ($players[0]['id'] ?? 0);
        $playerBId = (int) ($players[1]['id'] ?? 0);
        $resolvedAttributeKey = (string) ($attribute['key'] ?? $attributeKey);

        if ($playerAId <= 0 || $playerBId <= 0 || $playerAId === $playerBId || $resolvedAttributeKey === '') {
            return null;
        }

        return new InteractionOpportunity(
            source: 'duel',
            type: 'pair',
            payload: [
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'player_a_name' => (string) ($players[0]['name'] ?? ''),
                'player_b_name' => (string) ($players[1]['name'] ?? ''),
                'attribute' => $resolvedAttributeKey,
            ],
        );
    }
}
