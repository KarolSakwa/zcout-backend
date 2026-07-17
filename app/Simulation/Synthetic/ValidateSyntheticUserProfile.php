<?php

namespace App\Simulation\Synthetic;

use DomainException;

final class ValidateSyntheticUserProfile
{
    /**
     * @param array{
     *     decision_profile?: mixed,
     *     sessions_per_day_min?: mixed,
     *     sessions_per_day_max?: mixed,
     *     actions_per_session_min?: mixed,
     *     actions_per_session_max?: mixed,
     *     delay_seconds_min?: mixed,
     *     delay_seconds_max?: mixed,
     *     skip_probability?: mixed,
     *     decision_accuracy?: mixed,
     *     noise_level?: mixed,
     *     is_enabled?: mixed
     * } $attributes
     */
    public function validate(array $attributes): void
    {
        $decisionProfile = (string) ($attributes['decision_profile'] ?? '');
        if (! SyntheticDecisionProfiles::isAllowed($decisionProfile)) {
            throw new DomainException(
                'Invalid synthetic decision_profile. Allowed: ' . SyntheticDecisionProfiles::listForMessage() . '.',
            );
        }

        $sessionsMin = $this->requireNonNegativeInt($attributes, 'sessions_per_day_min');
        $sessionsMax = $this->requireNonNegativeInt($attributes, 'sessions_per_day_max');
        if ($sessionsMax < $sessionsMin) {
            throw new DomainException('sessions_per_day_max must be greater than or equal to sessions_per_day_min.');
        }

        $actionsMin = $this->requirePositiveInt($attributes, 'actions_per_session_min');
        $actionsMax = $this->requirePositiveInt($attributes, 'actions_per_session_max');
        if ($actionsMax < $actionsMin) {
            throw new DomainException('actions_per_session_max must be greater than or equal to actions_per_session_min.');
        }

        $delayMin = $this->requireNonNegativeInt($attributes, 'delay_seconds_min');
        $delayMax = $this->requireNonNegativeInt($attributes, 'delay_seconds_max');
        if ($delayMax < $delayMin) {
            throw new DomainException('delay_seconds_max must be greater than or equal to delay_seconds_min.');
        }

        $this->requireUnitInterval($attributes, 'skip_probability');
        $this->requireUnitInterval($attributes, 'decision_accuracy');
        $this->requireUnitInterval($attributes, 'noise_level');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requireNonNegativeInt(array $attributes, string $key): int
    {
        if (! array_key_exists($key, $attributes) || ! is_numeric($attributes[$key])) {
            throw new DomainException($key . ' must be an integer greater than or equal to 0.');
        }

        $value = (int) $attributes[$key];
        if ((float) $attributes[$key] != $value || $value < 0) {
            throw new DomainException($key . ' must be an integer greater than or equal to 0.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requirePositiveInt(array $attributes, string $key): int
    {
        if (! array_key_exists($key, $attributes) || ! is_numeric($attributes[$key])) {
            throw new DomainException($key . ' must be an integer greater than 0.');
        }

        $value = (int) $attributes[$key];
        if ((float) $attributes[$key] != $value || $value <= 0) {
            throw new DomainException($key . ' must be an integer greater than 0.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function requireUnitInterval(array $attributes, string $key): float
    {
        if (! array_key_exists($key, $attributes) || ! is_numeric($attributes[$key])) {
            throw new DomainException($key . ' must be a number between 0 and 1.');
        }

        $value = (float) $attributes[$key];
        if ($value < 0.0 || $value > 1.0) {
            throw new DomainException($key . ' must be a number between 0 and 1.');
        }

        return $value;
    }
}
