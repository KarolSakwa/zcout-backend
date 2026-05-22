<?php

namespace App\Simulation\Data;

final class SimulatedDuelVote
{
    public function __construct(
        public readonly string $simulatedUserId,
        public readonly bool $isLogged,
        public readonly ?int $appUserId,
        public readonly int $duelId,
        public readonly int $playerAId,
        public readonly int $playerBId,
        public readonly string $playerAName,
        public readonly string $playerBName,
        public readonly string $attributeKey,
        public readonly string $decisionType,
        public readonly int $step,
        public readonly ?int $winnerPlayerId = null,
    ) {
    }
}
