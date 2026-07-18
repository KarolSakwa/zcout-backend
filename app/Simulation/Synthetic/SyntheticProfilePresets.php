<?php

namespace App\Simulation\Synthetic;

use DomainException;

/**
 * Initial production synthetic profile presets by archetype.
 *
 * Presets are starting values applied when a profile is created. A stored
 * profile may later be customized independently. Production decision policy
 * uses the numeric fields, not the archetype name.
 */
final class SyntheticProfilePresets
{
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
    public static function for(string $profile): array
    {
        if (! SyntheticDecisionProfiles::isAllowed($profile)) {
            throw new DomainException(
                'Invalid synthetic decision_profile. Allowed: ' . SyntheticDecisionProfiles::listForMessage() . '.',
            );
        }

        return match ($profile) {
            SyntheticDecisionProfiles::EXPERT => [
                'decision_profile' => SyntheticDecisionProfiles::EXPERT,
                'sessions_per_day_min' => 1,
                'sessions_per_day_max' => 2,
                'actions_per_session_min' => 4,
                'actions_per_session_max' => 9,
                'delay_seconds_min' => 7,
                'delay_seconds_max' => 20,
                'skip_probability' => 0.08,
                'decision_accuracy' => 0.90,
                'noise_level' => 0.05,
                'is_enabled' => true,
            ],
            SyntheticDecisionProfiles::CASUAL => [
                'decision_profile' => SyntheticDecisionProfiles::CASUAL,
                'sessions_per_day_min' => 1,
                'sessions_per_day_max' => 2,
                'actions_per_session_min' => 3,
                'actions_per_session_max' => 8,
                'delay_seconds_min' => 6,
                'delay_seconds_max' => 20,
                'skip_probability' => 0.12,
                'decision_accuracy' => 0.72,
                'noise_level' => 0.15,
                'is_enabled' => true,
            ],
            SyntheticDecisionProfiles::NOISY => [
                'decision_profile' => SyntheticDecisionProfiles::NOISY,
                'sessions_per_day_min' => 1,
                'sessions_per_day_max' => 3,
                'actions_per_session_min' => 2,
                'actions_per_session_max' => 7,
                'delay_seconds_min' => 3,
                'delay_seconds_max' => 14,
                'skip_probability' => 0.18,
                'decision_accuracy' => 0.58,
                'noise_level' => 0.45,
                'is_enabled' => true,
            ],
        };
    }
}
