<?php

namespace App\Simulation\Synthetic;

class RandomIntRange
{
    public function between(int $min, int $max): int
    {
        if ($max < $min) {
            throw new \InvalidArgumentException('max must be greater than or equal to min.');
        }

        return random_int($min, $max);
    }
}
