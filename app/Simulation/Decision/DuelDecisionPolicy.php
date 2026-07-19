<?php

namespace App\Simulation\Decision;

final class DuelDecisionPolicy
{
    public function decide(
        string $decisionSeed,
        string $userType,
        int $playerAId,
        int $playerBId,
        string $attributeKey,
        float $truthRatingA,
        float $truthRatingB,
    ): DuelDecisionResult {
        $diff = $truthRatingA - $truthRatingB;
        $absDiff = abs($diff);

        $base = $decisionSeed;

        $skipRoll = abs(crc32($base . '|skip')) % 1000;
        $correctnessRoll = abs(crc32($base . '|correctness')) % 1000;
        $biasRoll = abs(crc32($base . '|bias')) % 1000;

        $skipThreshold = match ($userType) {
            'expert' => $absDiff < 3 ? 300 : ($absDiff < 8 ? 80 : 10),
            'casual' => $absDiff < 3 ? 220 : ($absDiff < 8 ? 95 : 30),
            'noisy' => $absDiff < 3 ? 180 : ($absDiff < 8 ? 90 : 35),
            'biased' => $absDiff < 3 ? 120 : ($absDiff < 8 ? 55 : 20),
            default => $absDiff < 3 ? 180 : ($absDiff < 8 ? 80 : 35),
        };

        if ($skipRoll < $skipThreshold) {
            return new DuelDecisionResult(type: 'skip');
        }

        $correctnessThreshold = match ($userType) {
            'expert' => $absDiff < 3 ? 860 : ($absDiff < 8 ? 945 : 985),
            'casual' => $absDiff < 3 ? 610 : ($absDiff < 8 ? 740 : 820),
            'noisy' => $absDiff < 3 ? 420 : ($absDiff < 8 ? 560 : 680),
            'biased' => $absDiff < 3 ? 560 : ($absDiff < 8 ? 700 : 820),
            default => $absDiff < 3 ? 500 : ($absDiff < 8 ? 650 : 780),
        };

        $preferredWinnerId = $diff >= 0 ? $playerAId : $playerBId;
        $oppositeWinnerId = $preferredWinnerId === $playerAId ? $playerBId : $playerAId;

        $winnerPlayerId = $correctnessRoll < $correctnessThreshold
            ? $preferredWinnerId
            : $oppositeWinnerId;

        if ($userType === 'biased' && $absDiff < 8) {
            $biasedPreferredWinnerId = $biasRoll < 500 ? $playerAId : $playerBId;

            if ($biasRoll < 350) {
                $winnerPlayerId = $biasedPreferredWinnerId;
            }
        }

        return new DuelDecisionResult(
            type: 'vote',
            winnerPlayerId: $winnerPlayerId,
            truthDiff: round($diff, 2),
        );
    }
}
