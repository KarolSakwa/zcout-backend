<?php

namespace App\Simulation\Data;

final class SimulatedUser
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly bool $isLogged,
        public readonly array $traits = [],
    ) {
    }
}
