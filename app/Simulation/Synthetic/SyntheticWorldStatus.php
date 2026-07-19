<?php

namespace App\Simulation\Synthetic;

final class SyntheticWorldStatus
{
    /**
     * @param array{expert: int, casual: int, noisy: int, invalid_unknown: int} $profiles
     * @param array{total: int, active: int, completed: int, failed: int, planned_actions_sum: int, completed_actions_sum: int, completion_ratio: float|null, average_planned_actions: float|null, average_completed_actions: float|null, sessions_completed_ratio: float|null} $worldSessions
     * @param array{started: int, active: int, completed: int, failed: int} $manualSessions
     * @param array{due_now: int, overdue_1_min: int, overdue_5_min: int, overdue_15_min: int, oldest_overdue_session_id: int|null, oldest_overdue_next_action_at: string|null, oldest_overdue_seconds: int|null} $execution
     * @param array{ok: int, failure: int, by_reason: array<string, int>, no_duel_available: int, unexpected_error: int, missing_live_rating: int, duplicate_vote: int} $lastActionStates
     * @param array{synthetic_votes: int, managed_pool_votes: int, manual_synthetic_votes: int, synthetic_skips: int, votes_by_profile: array{expert: int, casual: int, noisy: int, invalid_unknown: int}, skips_by_profile: array{expert: int, casual: int, noisy: int, invalid_unknown: int}, unique_synthetic_voters: int, average_votes_per_active_synthetic_user: float|null, latest_synthetic_vote_at: string|null} $activity
     * @param array{failed_sessions_today: int, failed_sessions_total: int, failed_today_by_reason: array<string, int>, latest_failed_session_id: int|null, latest_failed_at: string|null, latest_failed_reason: string|null} $failures
     * @param array{synthetic_locks_total: int, stale_synthetic_locks_1_min: int, stale_synthetic_locks_5_min: int, oldest_synthetic_lock_age_seconds: int|null} $locks
     * @param list<array{code: string, message: string}> $warnings
     * @param list<array<string, mixed>> $invalidProfileDetails
     * @param list<array<string, mixed>> $failedSessionDetails
     * @param list<array<string, mixed>> $overdueSessionDetails
     * @param list<array<string, mixed>> $staleLockDetails
     */
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly string $timezone,
        public readonly bool $automation_enabled,
        public readonly string $health,
        public readonly int $synthetic_users_total,
        public readonly int $managed_pool_users,
        public readonly int $manual_synthetic_users,
        public readonly int $enabled_profiles,
        public readonly int $disabled_profiles,
        public readonly int $missing_profiles,
        public readonly int $invalid_profiles,
        public readonly array $profiles,
        public readonly array $worldSessions,
        public readonly array $manualSessions,
        public readonly array $execution,
        public readonly array $lastActionStates,
        public readonly array $activity,
        public readonly array $failures,
        public readonly array $locks,
        public readonly ?string $latest_world_session_created_at,
        public readonly ?string $latest_session_advanced_at,
        public readonly array $warnings,
        public readonly array $invalidProfileDetails = [],
        public readonly array $failedSessionDetails = [],
        public readonly array $overdueSessionDetails = [],
        public readonly array $staleLockDetails = [],
        public readonly string $environment_automation = 'disabled',
        public readonly string $runtime_automation = 'paused',
        public readonly string $effective_automation = 'disabled',
        public readonly ?string $pause_mode = null,
        /** @var array<string, array<string, mixed>> */
        public readonly array $archetype_ranges = [],
        /** @var array{planned_sessions_today: int, started_sessions_today: int, remaining_sessions_today: int, daily_plan_exhausted: bool} */
        public readonly array $daily_plan = [
            'planned_sessions_today' => 0,
            'started_sessions_today' => 0,
            'remaining_sessions_today' => 0,
            'daily_plan_exhausted' => false,
        ],
        /** @var array{tick_started_at: ?string, tick_finished_at: ?string, tick_failed_at: ?string, last_error: ?string, last_progress_at: ?string, last_tick_duration_ms: ?int} */
        public readonly array $heartbeat = [
            'tick_started_at' => null,
            'tick_finished_at' => null,
            'tick_failed_at' => null,
            'last_error' => null,
            'last_progress_at' => null,
            'last_tick_duration_ms' => null,
        ],
        public readonly bool $mutex_present = false,
        public readonly ?int $mutex_age_seconds = null,
        public readonly bool $mutex_stale = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $execution = $this->execution;
        unset($execution['details']);

        $failures = $this->failures;
        unset($failures['details']);

        $locks = $this->locks;
        unset($locks['details']);

        return [
            'date' => $this->date,
            'time' => $this->time,
            'timezone' => $this->timezone,
            'automation_enabled' => $this->automation_enabled,
            'environment_automation' => $this->environment_automation,
            'runtime_automation' => $this->runtime_automation,
            'effective_automation' => $this->effective_automation,
            'pause_mode' => $this->pause_mode,
            'archetype_ranges' => $this->archetype_ranges,
            'daily_plan' => $this->daily_plan,
            'heartbeat' => $this->heartbeat,
            'mutex' => [
                'present' => $this->mutex_present,
                'age_seconds' => $this->mutex_age_seconds,
                'stale' => $this->mutex_stale,
            ],
            'health' => $this->health,
            'users' => [
                'synthetic_users_total' => $this->synthetic_users_total,
                'managed_pool_users' => $this->managed_pool_users,
                'manual_synthetic_users' => $this->manual_synthetic_users,
                'enabled_profiles' => $this->enabled_profiles,
                'disabled_profiles' => $this->disabled_profiles,
                'missing_profiles' => $this->missing_profiles,
                'invalid_profiles' => $this->invalid_profiles,
            ],
            'profiles' => $this->profiles,
            'world_sessions' => $this->worldSessions,
            'manual_sessions' => $this->manualSessions,
            'execution' => $execution,
            'last_action_states' => $this->lastActionStates,
            'activity' => $this->activity,
            'failures' => $failures,
            'locks' => $locks,
            'scheduler_signals' => [
                'latest_world_session_created_at' => $this->latest_world_session_created_at,
                'latest_session_advanced_at' => $this->latest_session_advanced_at,
                'latest_synthetic_vote_at' => $this->activity['latest_synthetic_vote_at'],
            ],
            'warnings' => $this->warnings,
            'details' => [
                'invalid_profiles' => $this->invalidProfileDetails,
                'failed_sessions' => $this->failedSessionDetails,
                'overdue_sessions' => $this->overdueSessionDetails,
                'stale_locks' => $this->staleLockDetails,
            ],
        ];
    }
}
