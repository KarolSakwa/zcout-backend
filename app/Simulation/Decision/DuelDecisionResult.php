<?php

namespace App\Simulation\Decision;

final class DuelDecisionResult
{
    public function __construct(
        public readonly string $type,
        public readonly ?int $winnerPlayerId = null,
        public readonly ?float $truthDiff = null,
    ) {
    }
}
