<?php

namespace App\Support;

use App\Enums\InfluenceProfile;
use InvalidArgumentException;

final class VoteWeightResolver
{
    public function __construct(
        private readonly VoteInfluenceProfilePolicy $profilePolicy = new VoteInfluenceProfilePolicy(),
    ) {
    }

    public function resolve(bool $isAnonymous, ?InfluenceProfile $influenceProfile): VoteWeights
    {
        if ($isAnonymous && $influenceProfile !== null) {
            throw new InvalidArgumentException('Anonymous voter cannot have influence profile.');
        }

        if (! $isAnonymous && $influenceProfile === null) {
            throw new InvalidArgumentException('Logged voter must have influence profile.');
        }

        if ($isAnonymous) {
            return new VoteWeights(
                ratingWeight: 0.5,
                confidenceWeight: 0.1,
            );
        }

        return new VoteWeights(
            ratingWeight: $this->profilePolicy->baseRatingWeight($influenceProfile),
            confidenceWeight: $this->profilePolicy->baseConfidenceWeight($influenceProfile),
        );
    }
}
