<?php

namespace App\Simulation\Data;

final class TruthAwareDuel
{
    public function __construct(
        public readonly int $playerAId,
        public readonly int $playerBId,
        public readonly string $attributeKey,
        public readonly ?float $truthRatingA,
        public readonly ?float $truthRatingB,
    ) {
    }
}
