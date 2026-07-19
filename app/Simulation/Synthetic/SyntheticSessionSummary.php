<?php

namespace App\Simulation\Synthetic;

final class SyntheticSessionSummary
{
    public function __construct(
        public readonly int $votes,
        public readonly int $skips,
        public readonly int $failures,
        public readonly bool $completed,
    ) {
    }
}
