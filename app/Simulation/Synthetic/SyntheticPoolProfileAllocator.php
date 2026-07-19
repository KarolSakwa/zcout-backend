<?php

namespace App\Simulation\Synthetic;

use DomainException;
use InvalidArgumentException;

final class SyntheticPoolProfileAllocator
{
    public function allocate(
        string $poolKey,
        int $index,
        int $expertPercent,
        int $casualPercent,
        int $noisyPercent,
    ): string {
        $this->assertDistribution($expertPercent, $casualPercent, $noisyPercent);

        if ($index < 1) {
            throw new InvalidArgumentException('pool index must be greater than or equal to 1.');
        }

        $digest = hash('sha256', 'synthetic-pool-profile|'.$poolKey.'|'.$index);
        $bucket = hexdec(substr($digest, 0, 8)) % 100;

        if ($bucket < $expertPercent) {
            return SyntheticDecisionProfiles::EXPERT;
        }

        if ($bucket < $expertPercent + $casualPercent) {
            return SyntheticDecisionProfiles::CASUAL;
        }

        return SyntheticDecisionProfiles::NOISY;
    }

    public function assertDistribution(int $expertPercent, int $casualPercent, int $noisyPercent): void
    {
        foreach (['expert' => $expertPercent, 'casual' => $casualPercent, 'noisy' => $noisyPercent] as $label => $value) {
            if ($value < 0 || $value > 100) {
                throw new DomainException(sprintf(
                    'Invalid %s percent [%d]. Expected an integer between 0 and 100.',
                    $label,
                    $value,
                ));
            }
        }

        $sum = $expertPercent + $casualPercent + $noisyPercent;
        if ($sum !== 100) {
            throw new DomainException(sprintf(
                'Profile percents must sum to exactly 100 (got %d).',
                $sum,
            ));
        }
    }
}
