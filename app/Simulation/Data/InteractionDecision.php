<?php

namespace App\Simulation\Data;

final class InteractionDecision
{
    public function __construct(
        public readonly string $source,
        public readonly string $type,
        public readonly array $payload = [],
    ) {
    }
}
