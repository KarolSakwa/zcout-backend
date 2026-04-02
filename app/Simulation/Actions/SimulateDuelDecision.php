<?php

namespace App\Simulation\Actions;

use App\Simulation\Data\InteractionDecision;
use App\Simulation\Data\InteractionOpportunity;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\SimulationContext;

final class SimulateDuelDecision
{
    public function __construct(
        private readonly BuildTruthAwareDuel $truthAwareDuelBuilder = new BuildTruthAwareDuel(),
    ) {
    }

    public function handle(
        InteractionOpportunity $opportunity,
        SimulatedUser $user,
        SimulationContext $context
    ): ?InteractionDecision {
        $duel = $this->truthAwareDuelBuilder->handle($opportunity, $context);

        if ($duel->truthRatingA === null || $duel->truthRatingB === null) {
            return new InteractionDecision(
                source: 'duel',
                type: 'skip',
                payload: [],
            );
        }

        $diff = $duel->truthRatingA - $duel->truthRatingB;
        $absDiff = abs($diff);

        $base = implode('|', [
            $context->runId,
            $context->currentStep,
            $user->id,
            $user->type,
            $duel->playerAId,
            $duel->playerBId,
            $duel->attributeKey,
        ]);

        $skipRoll = abs(crc32($base . '|skip')) % 1000;
        $correctnessRoll = abs(crc32($base . '|correctness')) % 1000;
        $biasRoll = abs(crc32($base . '|bias')) % 1000;

        $skipThreshold = match ($user->type) {
            'expert' => $absDiff < 3 ? 300 : ($absDiff < 8 ? 80 : 10),
            'casual' => $absDiff < 3 ? 220 : ($absDiff < 8 ? 95 : 30),
            'noisy' => $absDiff < 3 ? 180 : ($absDiff < 8 ? 90 : 35),
            'biased' => $absDiff < 3 ? 120 : ($absDiff < 8 ? 55 : 20),
            default => $absDiff < 3 ? 180 : ($absDiff < 8 ? 80 : 35),
        };

        if ($skipRoll < $skipThreshold) {
            return new InteractionDecision(
                source: 'duel',
                type: 'skip',
                payload: [],
            );
        }

        $correctnessThreshold = match ($user->type) {
            'expert' => $absDiff < 3 ? 860 : ($absDiff < 8 ? 945 : 985),
            'casual' => $absDiff < 3 ? 610 : ($absDiff < 8 ? 740 : 820),
            'noisy' => $absDiff < 3 ? 420 : ($absDiff < 8 ? 560 : 680),
            'biased' => $absDiff < 3 ? 560 : ($absDiff < 8 ? 700 : 820),
            default => $absDiff < 3 ? 500 : ($absDiff < 8 ? 650 : 780),
        };

        $preferredWinnerId = $diff >= 0 ? $duel->playerAId : $duel->playerBId;
        $oppositeWinnerId = $preferredWinnerId === $duel->playerAId ? $duel->playerBId : $duel->playerAId;

        $winnerPlayerId = $correctnessRoll < $correctnessThreshold
            ? $preferredWinnerId
            : $oppositeWinnerId;

        if ($user->type === 'biased' && $absDiff < 8) {
            $biasedPreferredWinnerId = $biasRoll < 500 ? $duel->playerAId : $duel->playerBId;

            if ($biasRoll < 350) {
                $winnerPlayerId = $biasedPreferredWinnerId;
            }
        }

        return new InteractionDecision(
            source: 'duel',
            type: 'vote',
            payload: [
                'winner_player_id' => $winnerPlayerId,
                'truth_diff' => round($diff, 2),
            ],
        );
    }
}
