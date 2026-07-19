<?php

namespace App\Simulation\Synthetic;

final class SyntheticUserProfileDefaults
{
    public const DECISION_PROFILE = SyntheticDecisionProfiles::CASUAL;

    /**
     * @return array{
     *     decision_profile: string,
     *     sessions_per_day_min: int,
     *     sessions_per_day_max: int,
     *     actions_per_session_min: int,
     *     actions_per_session_max: int,
     *     delay_seconds_min: int,
     *     delay_seconds_max: int,
     *     skip_probability: float,
     *     decision_accuracy: float,
     *     noise_level: float,
     *     is_enabled: bool
     * }
     */
    public static function attributes(): array
    {
        return SyntheticProfilePresets::for(self::DECISION_PROFILE);
    }
}
