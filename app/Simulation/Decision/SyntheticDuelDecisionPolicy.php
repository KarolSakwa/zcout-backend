<?php

namespace App\Simulation\Decision;

use DomainException;

final class SyntheticDuelDecisionPolicy
{
    public function decide(
        string $decisionSeed,
        int $playerAId,
        int $playerBId,
        float $ratingA,
        float $ratingB,
        float $skipProbability,
        float $decisionAccuracy,
        float $noiseLevel,
    ): DuelDecisionResult {
        $this->assertUnitInterval('skipProbability', $skipProbability);
        $this->assertUnitInterval('decisionAccuracy', $decisionAccuracy);
        $this->assertUnitInterval('noiseLevel', $noiseLevel);

        $skipRoll = $this->unitInterval($decisionSeed, 'synthetic-skip');
        if ($skipRoll < $skipProbability) {
            return new DuelDecisionResult(type: 'skip');
        }

        $ratingDiff = round($ratingA - $ratingB, 2);

        if ($ratingA === $ratingB) {
            $tieRoll = $this->unitInterval($decisionSeed, 'synthetic-tie');
            $winnerPlayerId = $tieRoll < 0.5 ? $playerAId : $playerBId;

            return new DuelDecisionResult(
                type: 'vote',
                winnerPlayerId: $winnerPlayerId,
                truthDiff: $ratingDiff,
            );
        }

        // noise moves accuracy toward a fair coin flip; noise=1 => 50/50, never intentional reverse voting
        $effectiveAccuracy = 0.5 + ($decisionAccuracy - 0.5) * (1.0 - $noiseLevel);
        $effectiveAccuracy = max(0.0, min(1.0, $effectiveAccuracy));

        $higherId = $ratingA > $ratingB ? $playerAId : $playerBId;
        $lowerId = $higherId === $playerAId ? $playerBId : $playerAId;

        $correctnessRoll = $this->unitInterval($decisionSeed, 'synthetic-correctness');
        $winnerPlayerId = $correctnessRoll < $effectiveAccuracy ? $higherId : $lowerId;

        return new DuelDecisionResult(
            type: 'vote',
            winnerPlayerId: $winnerPlayerId,
            truthDiff: $ratingDiff,
        );
    }

    private function assertUnitInterval(string $name, float $value): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new DomainException(sprintf(
                '%s must be a number between 0 and 1 (got %s).',
                $name,
                (string) $value,
            ));
        }
    }

    /**
     * Deterministic roll in [0.0, 1.0).
     */
    private function unitInterval(string $decisionSeed, string $suffix): float
    {
        $digest = hash('sha256', $decisionSeed.'|'.$suffix);
        $bucket = hexdec(substr($digest, 0, 8)) % 1_000_000;

        return $bucket / 1_000_000;
    }
}
