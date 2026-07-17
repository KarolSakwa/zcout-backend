<?php

namespace App\Simulation\Decision;

final class SimulationLabDecisionSeed
{
    public static function build(
        int $runId,
        int $currentStep,
        string $userId,
        string $userType,
        int $playerAId,
        int $playerBId,
        string $attributeKey,
    ): string {
        return implode('|', [
            $runId,
            $currentStep,
            $userId,
            $userType,
            $playerAId,
            $playerBId,
            $attributeKey,
        ]);
    }
}
