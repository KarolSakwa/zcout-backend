<?php

namespace App\Support;

final readonly class VoteWeights
{
    public function __construct(
        public float $ratingWeight,
        public float $confidenceWeight,
    ) {
    }
}
