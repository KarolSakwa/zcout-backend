<?php

namespace App\Simulation\Decision;

final class SyntheticSessionDecisionSeed
{
    public static function build(
        int $userId,
        string $profile,
        string $sessionId,
        int $actionIndex,
        int $playerAId,
        int $playerBId,
        string $attributeKey,
    ): string {
        return implode('|', [
            'synthetic-session',
            (string) $userId,
            $profile,
            $sessionId,
            $actionIndex,
            $playerAId,
            $playerBId,
            $attributeKey,
        ]);
    }
}
