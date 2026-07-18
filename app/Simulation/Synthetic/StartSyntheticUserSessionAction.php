<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticUserSession;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Str;

class StartSyntheticUserSessionAction
{
    public function __construct(
        private readonly ValidateSyntheticUserProfile $validateSyntheticUserProfile,
        private readonly RandomIntRange $randomIntRange,
    ) {
    }

    /**
     * @param  array{
     *     activity_date: CarbonInterface|string,
     *     daily_session_index: int,
     *     scheduled_start_at: CarbonInterface,
     *     session_seed: string
     * }|null  $worldMetadata
     */
    public function execute(User $user, ?array $worldMetadata = null): SyntheticUserSession
    {
        if (! $user->is_synthetic) {
            throw new DomainException(sprintf('User [%d] is not a synthetic user.', $user->id));
        }

        $profile = $user->syntheticProfile;
        if ($profile === null) {
            throw new DomainException(sprintf('Synthetic user [%d] does not have a profile.', $user->id));
        }

        if (! $profile->is_enabled) {
            throw new DomainException(sprintf('Synthetic user [%d] profile is disabled.', $user->id));
        }

        $this->validateSyntheticUserProfile->validate([
            'decision_profile' => $profile->decision_profile,
            'sessions_per_day_min' => $profile->sessions_per_day_min,
            'sessions_per_day_max' => $profile->sessions_per_day_max,
            'actions_per_session_min' => $profile->actions_per_session_min,
            'actions_per_session_max' => $profile->actions_per_session_max,
            'delay_seconds_min' => $profile->delay_seconds_min,
            'delay_seconds_max' => $profile->delay_seconds_max,
            'skip_probability' => $profile->skip_probability,
            'decision_accuracy' => $profile->decision_accuracy,
            'noise_level' => $profile->noise_level,
            'is_enabled' => $profile->is_enabled,
        ]);

        if (! SyntheticDecisionProfiles::isAllowed((string) $profile->decision_profile)) {
            throw new DomainException(
                'Invalid synthetic decision_profile. Allowed: ' . SyntheticDecisionProfiles::listForMessage() . '.',
            );
        }

        $plannedActions = $this->randomIntRange->between(
            (int) $profile->actions_per_session_min,
            (int) $profile->actions_per_session_max,
        );

        if ($plannedActions <= 0) {
            throw new DomainException('planned_actions must be greater than 0.');
        }

        $now = now();
        $activityDate = null;
        $dailySessionIndex = null;
        $scheduledStartAt = null;
        $sessionSeed = (string) Str::uuid();

        if ($worldMetadata !== null) {
            $this->assertWorldMetadata($worldMetadata);
            $activityDate = $worldMetadata['activity_date'];
            $dailySessionIndex = (int) $worldMetadata['daily_session_index'];
            $scheduledStartAt = $worldMetadata['scheduled_start_at'];
            $sessionSeed = (string) $worldMetadata['session_seed'];
        }

        return SyntheticUserSession::query()->create([
            'user_id' => $user->id,
            'status' => SyntheticSessionStatuses::ACTIVE,
            'planned_actions' => $plannedActions,
            'completed_actions' => 0,
            'next_action_at' => $now,
            'started_at' => $now,
            'completed_at' => null,
            'session_seed' => $sessionSeed,
            'last_action_status' => null,
            'last_action_reason' => null,
            'activity_date' => $activityDate,
            'daily_session_index' => $dailySessionIndex,
            'scheduled_start_at' => $scheduledStartAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $worldMetadata
     */
    private function assertWorldMetadata(array $worldMetadata): void
    {
        foreach (['activity_date', 'daily_session_index', 'scheduled_start_at', 'session_seed'] as $key) {
            if (! array_key_exists($key, $worldMetadata) || $worldMetadata[$key] === null || $worldMetadata[$key] === '') {
                throw new DomainException('World session metadata is incomplete: missing '.$key.'.');
            }
        }

        if ((int) $worldMetadata['daily_session_index'] < 1) {
            throw new DomainException('daily_session_index must be greater than or equal to 1.');
        }
    }
}
