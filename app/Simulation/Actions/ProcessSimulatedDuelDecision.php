<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedDuelVote;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;
use RuntimeException;

final class ProcessSimulatedDuelDecision
{
    public function __construct(
        private readonly MaterializeSimulatedDuelVote $materializer = new MaterializeSimulatedDuelVote(),
    ) {
    }

    public function handle(
        SimulatedUser $user,
        InteractionOpportunity $opportunity,
        InteractionDecision $decision,
        SimulationContext $context
    ): void {
        if ($opportunity->source !== 'duel') {
            throw new RuntimeException('ProcessSimulatedDuelDecision supports only duel opportunities.');
        }

        if ($decision->source !== 'duel') {
            throw new RuntimeException('ProcessSimulatedDuelDecision supports only duel decisions.');
        }

        if ($opportunity->type !== 'pair') {
            throw new RuntimeException("Unsupported duel opportunity type [{$opportunity->type}].");
        }

        if (! in_array($decision->type, ['vote_left', 'vote_right', 'skip'], true)) {
            throw new RuntimeException("Unsupported duel decision type [{$decision->type}].");
        }

        if (! array_key_exists('player_a_id', $opportunity->payload)) {
            throw new RuntimeException('Missing [player_a_id] in duel opportunity payload.');
        }

        if (! array_key_exists('player_b_id', $opportunity->payload)) {
            throw new RuntimeException('Missing [player_b_id] in duel opportunity payload.');
        }

        if (! array_key_exists('attribute', $opportunity->payload)) {
            throw new RuntimeException('Missing [attribute] in duel opportunity payload.');
        }

        $vote = new SimulatedDuelVote(
            simulatedUserId: $user->id,
            isLogged: $user->isLogged,
            playerAId: (int) $opportunity->payload['player_a_id'],
            playerBId: (int) $opportunity->payload['player_b_id'],
            attributeKey: (string) $opportunity->payload['attribute'],
            decisionType: $decision->type,
            step: $context->currentStep,
        );

        $this->materializer->handle($vote, $context);
    }
}
