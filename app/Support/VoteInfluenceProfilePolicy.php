<?php

namespace App\Support;

use App\Enums\InfluenceProfile;

final class VoteInfluenceProfilePolicy
{
    public function baseRatingWeight(InfluenceProfile $profile): float
    {
        return match ($profile) {
            InfluenceProfile::USER_DEFAULT => 1.0,
            InfluenceProfile::SCOUT_FOUNDER => 2.0,
        };
    }

    public function baseConfidenceWeight(InfluenceProfile $profile): float
    {
        return match ($profile) {
            InfluenceProfile::USER_DEFAULT => 1.0,
            InfluenceProfile::SCOUT_FOUNDER => 3.0,
        };
    }
}
