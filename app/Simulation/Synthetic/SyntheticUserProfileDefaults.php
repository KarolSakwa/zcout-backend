<?php

namespace App\Simulation\Synthetic;

final class SyntheticUserProfileDefaults
{
    public const DECISION_PROFILE = SyntheticDecisionProfiles::CASUAL;
    public const SESSIONS_PER_DAY_MIN = 1;
    public const SESSIONS_PER_DAY_MAX = 2;
    public const ACTIONS_PER_SESSION_MIN = 3;
    public const ACTIONS_PER_SESSION_MAX = 8;
    public const DELAY_SECONDS_MIN = 6;
    public const DELAY_SECONDS_MAX = 20;
    public const SKIP_PROBABILITY = 0.12;
    public const DECISION_ACCURACY = 0.72;
    public const NOISE_LEVEL = 0.15;
    public const IS_ENABLED = true;

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
        return [
            'decision_profile' => self::DECISION_PROFILE,
            'sessions_per_day_min' => self::SESSIONS_PER_DAY_MIN,
            'sessions_per_day_max' => self::SESSIONS_PER_DAY_MAX,
            'actions_per_session_min' => self::ACTIONS_PER_SESSION_MIN,
            'actions_per_session_max' => self::ACTIONS_PER_SESSION_MAX,
            'delay_seconds_min' => self::DELAY_SECONDS_MIN,
            'delay_seconds_max' => self::DELAY_SECONDS_MAX,
            'skip_probability' => self::SKIP_PROBABILITY,
            'decision_accuracy' => self::DECISION_ACCURACY,
            'noise_level' => self::NOISE_LEVEL,
            'is_enabled' => self::IS_ENABLED,
        ];
    }
}
