<?php

namespace App\Simulation;

final class SimulationContext
{
    public function __construct(
        public readonly string $mode,
        public readonly int $runId,
        public readonly \DateTimeImmutable $now,
        public readonly array $config = [],
        public readonly int $currentStep = 0,
    ) {
    }
}
