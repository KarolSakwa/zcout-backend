<?php

namespace App\Simulation\Synthetic;

use App\Actions\Duels\AuthenticatedVoterLockKey;
use App\Models\SyntheticUserProfile;
use App\Models\SyntheticUserSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class GetSyntheticWorldStatusAction
{
    private const DETAILS_LIMIT = 20;

    private const NO_PROGRESS_SECONDS = 180;

    public function __construct(
        private readonly ValidateSyntheticUserProfile $validateSyntheticUserProfile,
        private readonly SyntheticWorldRuntime $runtime,
        private readonly SyntheticWorldScheduleMutex $mutex,
        private readonly SyntheticDailyActivityPlanner $planner,
    ) {
    }

    public function execute(?string $date = null, bool $includeDetails = false): SyntheticWorldStatus
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $reportDate = $this->resolveReportDate($date, $timezone);
        $dayStart = $reportDate->startOfDay();
        $dayEnd = $reportDate->endOfDay();
        $dayStartUtc = $dayStart->utc();
        $dayEndUtc = $dayEnd->utc();
        $dateString = $reportDate->toDateString();

        $users = $this->collectUserCounts();
        $invalid = $this->collectInvalidProfiles($includeDetails);
        $profiles = $this->collectProfileDistribution();
        $worldSessions = $this->collectWorldSessions($dateString);
        $manualSessions = $this->collectManualSessions($dayStartUtc, $dayEndUtc);
        $execution = $this->collectExecution($now, $includeDetails);
        $lastActionStates = $this->collectLastActionStates($dateString);
        $activity = $this->collectActivity($dayStartUtc, $dayEndUtc);
        $failures = $this->collectFailures($dateString, $includeDetails);
        $locks = $this->collectLocks($now, $includeDetails);

        $latestWorldSessionCreatedAt = SyntheticUserSession::query()
            ->whereNotNull('activity_date')
            ->max('created_at');

        $latestSessionAdvancedAt = SyntheticUserSession::query()
            ->whereIn('status', [SyntheticSessionStatuses::ACTIVE, SyntheticSessionStatuses::COMPLETED])
            ->max('updated_at');

        $environmentEnabled = $this->runtime->environmentEnabled();
        $runtimeSettings = $this->runtime->current();
        $dailyPlan = $this->collectDailyPlan($dateString);
        $archetypeRanges = $this->collectArchetypeRanges();
        try {
            $mutexPresent = $this->mutex->exists();
            $mutexStale = $this->mutex->isStale();
            $mutexAge = $this->mutex->ageSeconds();
        } catch (\Throwable) {
            $mutexPresent = false;
            $mutexStale = false;
            $mutexAge = null;
        }

        $health = $this->resolveHealth(
            automationEnabled: $environmentEnabled && $this->runtime->effectiveEnabled(),
            enabledProfiles: $users['enabled_profiles'],
            overdue5: $execution['overdue_5_min'],
            overdue15: $execution['overdue_15_min'],
            worldSessionsTotal: $worldSessions['total'],
            syntheticVotes: $activity['synthetic_votes'],
            syntheticUsersTotal: $users['synthetic_users_total'],
        );

        $warnings = $this->buildWarnings(
            automationEnabled: $environmentEnabled,
            users: $users,
            invalidProfiles: $invalid['count'],
            failedToday: $failures['failed_sessions_today'],
            overdue1: $execution['overdue_1_min'],
            staleLocks1: $locks['stale_synthetic_locks_1_min'],
            reportDateIsToday: $dateString === $now->toDateString(),
            syntheticVotes: $activity['synthetic_votes'],
            worldSessionsTotal: $worldSessions['total'],
            latestVoteAt: $activity['latest_synthetic_vote_at'],
            effectiveEnabled: $this->runtime->effectiveEnabled(),
            dueNow: $execution['due_now'],
            dailyPlanExhausted: $dailyPlan['daily_plan_exhausted'],
            lastProgressAt: $runtimeSettings->last_progress_at?->toIso8601String(),
            mutexStale: $mutexStale,
            now: $now,
        );

        return new SyntheticWorldStatus(
            date: $dateString,
            time: $now->toIso8601String(),
            timezone: $timezone,
            automation_enabled: $environmentEnabled,
            health: $health,
            synthetic_users_total: $users['synthetic_users_total'],
            managed_pool_users: $users['managed_pool_users'],
            manual_synthetic_users: $users['manual_synthetic_users'],
            enabled_profiles: $users['enabled_profiles'],
            disabled_profiles: $users['disabled_profiles'],
            missing_profiles: $users['missing_profiles'],
            invalid_profiles: $invalid['count'],
            profiles: $profiles,
            worldSessions: $worldSessions,
            manualSessions: $manualSessions,
            execution: $execution,
            lastActionStates: $lastActionStates,
            activity: $activity,
            failures: $failures,
            locks: $locks,
            latest_world_session_created_at: $this->toIsoOrNull($latestWorldSessionCreatedAt),
            latest_session_advanced_at: $this->toIsoOrNull($latestSessionAdvancedAt),
            warnings: $warnings,
            invalidProfileDetails: $includeDetails ? $invalid['details'] : [],
            failedSessionDetails: $includeDetails ? $failures['details'] : [],
            overdueSessionDetails: $includeDetails ? $execution['details'] : [],
            staleLockDetails: $includeDetails ? $locks['details'] : [],
            environment_automation: $environmentEnabled ? 'enabled' : 'disabled',
            runtime_automation: $this->runtime->runtimeLabel(),
            effective_automation: $this->runtime->effectiveLabel(),
            pause_mode: $runtimeSettings->pause_mode,
            archetype_ranges: $archetypeRanges,
            daily_plan: $dailyPlan,
            heartbeat: [
                'tick_started_at' => $this->toIsoOrNull($runtimeSettings->tick_started_at),
                'tick_finished_at' => $this->toIsoOrNull($runtimeSettings->tick_finished_at),
                'tick_failed_at' => $this->toIsoOrNull($runtimeSettings->tick_failed_at),
                'last_error' => $runtimeSettings->last_error,
                'last_progress_at' => $this->toIsoOrNull($runtimeSettings->last_progress_at),
                'last_tick_duration_ms' => $runtimeSettings->last_tick_duration_ms,
            ],
            mutex_present: $mutexPresent,
            mutex_age_seconds: $mutexAge,
            mutex_stale: $mutexStale,
        );
    }

    private function resolveReportDate(?string $date, string $timezone): CarbonImmutable
    {
        if ($date === null || $date === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new DomainException('Invalid --date. Expected YYYY-MM-DD.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Invalid --date. Expected a real calendar date in YYYY-MM-DD format.');
        }

        return $parsed->startOfDay();
    }

    /**
     * @return array{
     *     synthetic_users_total: int,
     *     managed_pool_users: int,
     *     manual_synthetic_users: int,
     *     enabled_profiles: int,
     *     disabled_profiles: int,
     *     missing_profiles: int
     * }
     */
    private function collectUserCounts(): array
    {
        $syntheticUsersTotal = (int) User::query()->where('is_synthetic', true)->count();
        $managedPoolUsers = (int) User::query()
            ->where('is_synthetic', true)
            ->whereNotNull('synthetic_pool_key')
            ->count();
        $manualSyntheticUsers = (int) User::query()
            ->where('is_synthetic', true)
            ->whereNull('synthetic_pool_key')
            ->count();

        $enabledProfiles = (int) SyntheticUserProfile::query()->where('is_enabled', true)->count();
        $disabledProfiles = (int) SyntheticUserProfile::query()->where('is_enabled', false)->count();

        $missingProfiles = (int) User::query()
            ->where('is_synthetic', true)
            ->whereDoesntHave('syntheticProfile')
            ->count();

        return [
            'synthetic_users_total' => $syntheticUsersTotal,
            'managed_pool_users' => $managedPoolUsers,
            'manual_synthetic_users' => $manualSyntheticUsers,
            'enabled_profiles' => $enabledProfiles,
            'disabled_profiles' => $disabledProfiles,
            'missing_profiles' => $missingProfiles,
        ];
    }

    /**
     * @return array{count: int, details: list<array<string, mixed>>}
     */
    private function collectInvalidProfiles(bool $includeDetails): array
    {
        $count = 0;
        $details = [];

        SyntheticUserProfile::query()
            ->orderBy('id')
            ->chunkById(200, function ($profiles) use (&$count, &$details, $includeDetails): void {
                foreach ($profiles as $profile) {
                    try {
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
                    } catch (DomainException) {
                        $count++;
                        if ($includeDetails && count($details) < self::DETAILS_LIMIT) {
                            $details[] = [
                                'user_id' => (int) $profile->user_id,
                                'profile' => (string) $profile->decision_profile,
                                'is_enabled' => (bool) $profile->is_enabled,
                            ];
                        }
                    }
                }
            });

        return [
            'count' => $count,
            'details' => $details,
        ];
    }

    /**
     * @return array{expert: int, casual: int, noisy: int, invalid_unknown: int}
     */
    private function collectProfileDistribution(): array
    {
        $rows = SyntheticUserProfile::query()
            ->where('is_enabled', true)
            ->select('decision_profile', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('decision_profile')
            ->get();

        $distribution = [
            'expert' => 0,
            'casual' => 0,
            'noisy' => 0,
            'invalid_unknown' => 0,
        ];

        foreach ($rows as $row) {
            $profile = (string) $row->decision_profile;
            if (isset($distribution[$profile]) && $profile !== 'invalid_unknown') {
                $distribution[$profile] = (int) $row->aggregate_count;
            } else {
                $distribution['invalid_unknown'] += (int) $row->aggregate_count;
            }
        }

        return $distribution;
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     completed: int,
     *     failed: int,
     *     planned_actions_sum: int,
     *     completed_actions_sum: int,
     *     completion_ratio: float|null,
     *     average_planned_actions: float|null,
     *     average_completed_actions: float|null,
     *     sessions_completed_ratio: float|null
     * }
     */
    private function collectWorldSessions(string $dateString): array
    {
        $base = SyntheticUserSession::query()->whereDate('activity_date', $dateString);

        $total = (int) $base->clone()->count();
        $active = (int) $base->clone()->where('status', SyntheticSessionStatuses::ACTIVE)->count();
        $completed = (int) $base->clone()->where('status', SyntheticSessionStatuses::COMPLETED)->count();
        $failed = (int) $base->clone()->where('status', SyntheticSessionStatuses::FAILED)->count();

        $sums = $base->clone()
            ->selectRaw('COALESCE(SUM(planned_actions), 0) as planned_sum')
            ->selectRaw('COALESCE(SUM(completed_actions), 0) as completed_sum')
            ->first();

        $plannedSum = (int) ($sums->planned_sum ?? 0);
        $completedSum = (int) ($sums->completed_sum ?? 0);

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'failed' => $failed,
            'planned_actions_sum' => $plannedSum,
            'completed_actions_sum' => $completedSum,
            'completion_ratio' => $plannedSum > 0 ? round($completedSum / $plannedSum, 4) : null,
            'average_planned_actions' => $total > 0 ? round($plannedSum / $total, 2) : null,
            'average_completed_actions' => $total > 0 ? round($completedSum / $total, 2) : null,
            'sessions_completed_ratio' => $total > 0 ? round($completed / $total, 4) : null,
        ];
    }

    /**
     * @return array{started: int, active: int, completed: int, failed: int}
     */
    private function collectManualSessions(CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $base = SyntheticUserSession::query()
            ->whereNull('activity_date')
            ->whereBetween('started_at', [$dayStart, $dayEnd]);

        return [
            'started' => (int) $base->clone()->count(),
            'active' => (int) $base->clone()->where('status', SyntheticSessionStatuses::ACTIVE)->count(),
            'completed' => (int) $base->clone()->where('status', SyntheticSessionStatuses::COMPLETED)->count(),
            'failed' => (int) $base->clone()->where('status', SyntheticSessionStatuses::FAILED)->count(),
        ];
    }

    /**
     * @return array{
     *     due_now: int,
     *     overdue_1_min: int,
     *     overdue_5_min: int,
     *     overdue_15_min: int,
     *     oldest_overdue_session_id: int|null,
     *     oldest_overdue_next_action_at: string|null,
     *     oldest_overdue_seconds: int|null,
     *     details: list<array<string, mixed>>
     * }
     */
    private function collectExecution(CarbonImmutable $now, bool $includeDetails): array
    {
        $dueBase = SyntheticUserSession::query()
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', $now);

        $dueNow = (int) $dueBase->clone()->count();
        $overdue1 = (int) $dueBase->clone()->where('next_action_at', '<=', $now->subMinutes(1))->count();
        $overdue5 = (int) $dueBase->clone()->where('next_action_at', '<=', $now->subMinutes(5))->count();
        $overdue15 = (int) $dueBase->clone()->where('next_action_at', '<=', $now->subMinutes(15))->count();

        $oldest = $dueBase->clone()
            ->where('next_action_at', '<=', $now->subMinutes(1))
            ->orderBy('next_action_at')
            ->first(['id', 'next_action_at']);

        $oldestId = $oldest?->id !== null ? (int) $oldest->id : null;
        $oldestAt = $oldest?->next_action_at;
        $oldestSeconds = $oldestAt !== null
            ? (int) $oldestAt->diffInSeconds($now)
            : null;

        $details = [];
        if ($includeDetails) {
            $details = $dueBase->clone()
                ->where('next_action_at', '<=', $now->subMinutes(1))
                ->orderBy('next_action_at')
                ->limit(self::DETAILS_LIMIT)
                ->get(['id', 'user_id', 'next_action_at', 'completed_actions', 'planned_actions', 'last_action_reason'])
                ->map(static fn (SyntheticUserSession $session): array => [
                    'session_id' => (int) $session->id,
                    'user_id' => (int) $session->user_id,
                    'next_action_at' => $session->next_action_at?->toIso8601String(),
                    'completed_actions' => (int) $session->completed_actions,
                    'planned_actions' => (int) $session->planned_actions,
                    'reason' => $session->last_action_reason,
                ])
                ->all();
        }

        return [
            'due_now' => $dueNow,
            'overdue_1_min' => $overdue1,
            'overdue_5_min' => $overdue5,
            'overdue_15_min' => $overdue15,
            'oldest_overdue_session_id' => $oldestId,
            'oldest_overdue_next_action_at' => $oldestAt?->toIso8601String(),
            'oldest_overdue_seconds' => $oldestSeconds,
            'details' => $details,
        ];
    }

    /**
     * Last-action snapshot for world sessions on the report date (not full action history).
     *
     * @return array{
     *     ok: int,
     *     failure: int,
     *     by_reason: array<string, int>,
     *     no_duel_available: int,
     *     unexpected_error: int,
     *     missing_live_rating: int,
     *     duplicate_vote: int
     * }
     */
    private function collectLastActionStates(string $dateString): array
    {
        $ok = (int) SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->where('last_action_status', 'ok')
            ->count();

        $failure = (int) SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->where('last_action_status', 'failure')
            ->count();

        $reasonRows = SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->whereNotNull('last_action_reason')
            ->select('last_action_reason', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('last_action_reason')
            ->pluck('aggregate_count', 'last_action_reason');

        $nullReason = (int) SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->whereNotNull('last_action_status')
            ->whereNull('last_action_reason')
            ->count();

        $byReason = [];
        foreach ($reasonRows as $reason => $count) {
            $byReason[(string) $reason] = (int) $count;
        }
        if ($nullReason > 0) {
            $byReason['unknown'] = ($byReason['unknown'] ?? 0) + $nullReason;
        }

        return [
            'ok' => $ok,
            'failure' => $failure,
            'by_reason' => $byReason,
            'no_duel_available' => (int) ($byReason['no_duel_available'] ?? 0),
            'unexpected_error' => (int) ($byReason['unexpected_error'] ?? 0),
            'missing_live_rating' => (int) ($byReason['missing_live_rating'] ?? 0),
            'duplicate_vote' => (int) ($byReason['duplicate_vote'] ?? 0),
        ];
    }

    /**
     * @return array{
     *     synthetic_votes: int,
     *     managed_pool_votes: int,
     *     manual_synthetic_votes: int,
     *     synthetic_skips: int,
     *     votes_by_profile: array{expert: int, casual: int, noisy: int, invalid_unknown: int},
     *     skips_by_profile: array{expert: int, casual: int, noisy: int, invalid_unknown: int},
     *     unique_synthetic_voters: int,
     *     average_votes_per_active_synthetic_user: float|null,
     *     latest_synthetic_vote_at: string|null
     * }
     */
    private function collectActivity(CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $votesBase = DB::table('votes as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->where('u.is_synthetic', true)
            ->where('v.source', 'duel')
            ->whereBetween('v.created_at', [$dayStart, $dayEnd]);

        $syntheticVotes = (int) $votesBase->clone()->count();
        $managedPoolVotes = (int) $votesBase->clone()->whereNotNull('u.synthetic_pool_key')->count();
        $manualSyntheticVotes = (int) $votesBase->clone()->whereNull('u.synthetic_pool_key')->count();
        $uniqueVoters = (int) $votesBase->clone()->selectRaw('COUNT(DISTINCT v.user_id) as aggregate_count')->value('aggregate_count');

        $latestVote = $votesBase->clone()->max('v.created_at');

        $votesByProfile = $this->emptyProfileBucket();
        $voteProfileRows = DB::table('votes as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->leftJoin('synthetic_user_profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.is_synthetic', true)
            ->where('v.source', 'duel')
            ->whereBetween('v.created_at', [$dayStart, $dayEnd])
            ->select('p.decision_profile', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('p.decision_profile')
            ->get();

        foreach ($voteProfileRows as $row) {
            $this->addToProfileBucket($votesByProfile, $row->decision_profile, (int) $row->aggregate_count);
        }

        $skipsBase = DB::table('duel_skips as ds')
            ->join('users as u', 'u.id', '=', 'ds.user_id')
            ->where('u.is_synthetic', true)
            ->whereBetween('ds.created_at', [$dayStart, $dayEnd]);

        $syntheticSkips = (int) $skipsBase->clone()->count();

        $skipsByProfile = $this->emptyProfileBucket();
        $skipProfileRows = DB::table('duel_skips as ds')
            ->join('users as u', 'u.id', '=', 'ds.user_id')
            ->leftJoin('synthetic_user_profiles as p', 'p.user_id', '=', 'u.id')
            ->where('u.is_synthetic', true)
            ->whereBetween('ds.created_at', [$dayStart, $dayEnd])
            ->select('p.decision_profile', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('p.decision_profile')
            ->get();

        foreach ($skipProfileRows as $row) {
            $this->addToProfileBucket($skipsByProfile, $row->decision_profile, (int) $row->aggregate_count);
        }

        return [
            'synthetic_votes' => $syntheticVotes,
            'managed_pool_votes' => $managedPoolVotes,
            'manual_synthetic_votes' => $manualSyntheticVotes,
            'synthetic_skips' => $syntheticSkips,
            'votes_by_profile' => $votesByProfile,
            'skips_by_profile' => $skipsByProfile,
            'unique_synthetic_voters' => $uniqueVoters,
            'average_votes_per_active_synthetic_user' => $uniqueVoters > 0
                ? round($syntheticVotes / $uniqueVoters, 2)
                : null,
            'latest_synthetic_vote_at' => $this->toIsoOrNull($latestVote),
        ];
    }

    /**
     * @return array{
     *     failed_sessions_today: int,
     *     failed_sessions_total: int,
     *     failed_today_by_reason: array<string, int>,
     *     latest_failed_session_id: int|null,
     *     latest_failed_at: string|null,
     *     latest_failed_reason: string|null,
     *     details: list<array<string, mixed>>
     * }
     */
    private function collectFailures(string $dateString, bool $includeDetails): array
    {
        $failedToday = (int) SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->where('status', SyntheticSessionStatuses::FAILED)
            ->count();

        $failedTotal = (int) SyntheticUserSession::query()
            ->where('status', SyntheticSessionStatuses::FAILED)
            ->count();

        $reasonRows = SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->where('status', SyntheticSessionStatuses::FAILED)
            ->selectRaw("COALESCE(last_action_reason, 'unknown') as reason_key")
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupByRaw("COALESCE(last_action_reason, 'unknown')")
            ->pluck('aggregate_count', 'reason_key');

        $byReason = [];
        foreach ($reasonRows as $reason => $count) {
            $byReason[(string) $reason] = (int) $count;
        }

        $latest = SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->where('status', SyntheticSessionStatuses::FAILED)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first(['id', 'completed_at', 'last_action_reason']);

        $details = [];
        if ($includeDetails) {
            $details = SyntheticUserSession::query()
                ->whereDate('activity_date', $dateString)
                ->where('status', SyntheticSessionStatuses::FAILED)
                ->orderByDesc('completed_at')
                ->limit(self::DETAILS_LIMIT)
                ->get(['id', 'user_id', 'last_action_reason', 'completed_at', 'completed_actions', 'planned_actions'])
                ->map(static fn (SyntheticUserSession $session): array => [
                    'session_id' => (int) $session->id,
                    'user_id' => (int) $session->user_id,
                    'reason' => $session->last_action_reason ?? 'unknown',
                    'completed_at' => $session->completed_at?->toIso8601String(),
                    'completed_actions' => (int) $session->completed_actions,
                    'planned_actions' => (int) $session->planned_actions,
                ])
                ->all();
        }

        return [
            'failed_sessions_today' => $failedToday,
            'failed_sessions_total' => $failedTotal,
            'failed_today_by_reason' => $byReason,
            'latest_failed_session_id' => $latest?->id !== null ? (int) $latest->id : null,
            'latest_failed_at' => $latest?->completed_at?->toIso8601String(),
            'latest_failed_reason' => $latest?->last_action_reason ?? ($latest !== null ? 'unknown' : null),
            'details' => $details,
        ];
    }

    /**
     * Locks joined with CONCAT('user:', users.id), matching AuthenticatedVoterLockKey::forUserId().
     *
     * @return array{
     *     synthetic_locks_total: int,
     *     stale_synthetic_locks_1_min: int,
     *     stale_synthetic_locks_5_min: int,
     *     oldest_synthetic_lock_age_seconds: int|null,
     *     details: list<array<string, mixed>>
     * }
     */
    private function collectLocks(CarbonImmutable $now, bool $includeDetails): array
    {
        // Must stay aligned with AuthenticatedVoterLockKey::forUserId().
        if (AuthenticatedVoterLockKey::forUserId(1) !== 'user:1') {
            throw new DomainException('Authenticated voter lock key format changed; update lock status join.');
        }

        $base = DB::table('voter_duel_locks as vdl')
            ->join('users as u', function ($join): void {
                $join->whereRaw("vdl.voter_hash = CONCAT('user:', u.id)")
                    ->where('u.is_synthetic', true);
            });

        $total = (int) $base->clone()->count();
        $stale1 = (int) $base->clone()->where('vdl.updated_at', '<=', $now->subMinutes(1))->count();
        $stale5 = (int) $base->clone()->where('vdl.updated_at', '<=', $now->subMinutes(5))->count();

        $oldestUpdatedAt = $base->clone()->min('vdl.updated_at');
        $oldestAge = $oldestUpdatedAt !== null
            ? (int) CarbonImmutable::parse($oldestUpdatedAt)->diffInSeconds($now)
            : null;

        $details = [];
        if ($includeDetails && $stale1 > 0) {
            $rows = $base->clone()
                ->where('vdl.updated_at', '<=', $now->subMinutes(1))
                ->orderBy('vdl.updated_at')
                ->limit(self::DETAILS_LIMIT)
                ->get(['u.id as user_id', 'vdl.duel_id', 'vdl.updated_at', 'vdl.created_at']);

            foreach ($rows as $row) {
                $details[] = [
                    'user_id' => (int) $row->user_id,
                    'duel_id' => (int) $row->duel_id,
                    'updated_at' => CarbonImmutable::parse($row->updated_at)->toIso8601String(),
                    'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
                    'age_seconds' => (int) CarbonImmutable::parse($row->updated_at)->diffInSeconds($now),
                ];
            }
        }

        return [
            'synthetic_locks_total' => $total,
            'stale_synthetic_locks_1_min' => $stale1,
            'stale_synthetic_locks_5_min' => $stale5,
            'oldest_synthetic_lock_age_seconds' => $oldestAge,
            'details' => $details,
        ];
    }

    /**
     * @param array{enabled_profiles: int, missing_profiles: int, synthetic_users_total: int} $users
     * @return list<array{code: string, message: string}>
     */
    private function buildWarnings(
        bool $automationEnabled,
        array $users,
        int $invalidProfiles,
        int $failedToday,
        int $overdue1,
        int $staleLocks1,
        bool $reportDateIsToday,
        int $syntheticVotes,
        int $worldSessionsTotal,
        ?string $latestVoteAt,
        bool $effectiveEnabled = false,
        int $dueNow = 0,
        bool $dailyPlanExhausted = false,
        ?string $lastProgressAt = null,
        bool $mutexStale = false,
        ?CarbonImmutable $now = null,
    ): array {
        $warnings = [];

        if ($users['missing_profiles'] > 0) {
            $warnings[] = [
                'code' => 'synthetic_users_missing_profiles',
                'message' => sprintf('%d synthetic user(s) have no profile.', $users['missing_profiles']),
            ];
        }

        if ($invalidProfiles > 0) {
            $warnings[] = [
                'code' => 'invalid_profiles_present',
                'message' => sprintf('%d synthetic profile(s) fail validation.', $invalidProfiles),
            ];
        }

        if ($failedToday > 0) {
            $warnings[] = [
                'code' => 'failed_sessions_present',
                'message' => sprintf('%d failed world session(s) on the report date.', $failedToday),
            ];
        }

        if ($overdue1 > 0) {
            $warnings[] = [
                'code' => 'overdue_sessions_present',
                'message' => sprintf('%d active session(s) are overdue by more than 1 minute.', $overdue1),
            ];
        }

        if ($staleLocks1 > 0) {
            $warnings[] = [
                'code' => 'stale_locks_present',
                'message' => sprintf('%d synthetic voter lock(s) are older than 1 minute.', $staleLocks1),
            ];
        }

        if ($users['enabled_profiles'] === 0) {
            $warnings[] = [
                'code' => 'no_enabled_synthetic_users',
                'message' => 'No enabled synthetic profiles are available.',
            ];
        }

        if (
            $automationEnabled
            && $reportDateIsToday
            && $users['enabled_profiles'] > 0
            && $syntheticVotes === 0
            && $worldSessionsTotal === 0
            && $latestVoteAt === null
        ) {
            $warnings[] = [
                'code' => 'automation_enabled_but_no_recent_activity',
                'message' => 'Automation is enabled but there are no world sessions or synthetic votes for today.',
            ];
        }

        if ($dailyPlanExhausted && $reportDateIsToday && $users['enabled_profiles'] > 0) {
            $warnings[] = [
                'code' => 'daily_plan_exhausted',
                'message' => 'All planned world sessions for today appear started; idle ticks are expected until tomorrow or higher sessions_* limits.',
            ];
        }

        if ($mutexStale) {
            $warnings[] = [
                'code' => 'stale_mutex',
                'message' => 'Schedule mutex looks stale (old tick_started_at without finish). Consider start --clear-stale-mutex after inspection.',
            ];
        }

        if (
            $effectiveEnabled
            && $reportDateIsToday
            && $dueNow > 0
            && ! $dailyPlanExhausted
            && $now !== null
        ) {
            $progressAt = $lastProgressAt !== null ? CarbonImmutable::parse($lastProgressAt) : null;
            $staleProgress = $progressAt === null || $progressAt->diffInSeconds($now) >= self::NO_PROGRESS_SECONDS;
            if ($staleProgress) {
                $warnings[] = [
                    'code' => 'no_progress',
                    'message' => 'Effective automation is enabled with due sessions, but no recent progress heartbeat was recorded.',
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return array{planned_sessions_today: int, started_sessions_today: int, remaining_sessions_today: int, daily_plan_exhausted: bool}
     */
    private function collectDailyPlan(string $dateString): array
    {
        $planned = 0;
        $remaining = 0;

        User::query()
            ->where('is_synthetic', true)
            ->whereHas('syntheticProfile', static fn ($q) => $q->where('is_enabled', true))
            ->with('syntheticProfile')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($dateString, &$planned, &$remaining): void {
                foreach ($users as $user) {
                    $profile = $user->syntheticProfile;
                    if ($profile === null) {
                        continue;
                    }

                    $target = $this->planner->targetSessionsToday(
                        userId: (int) $user->id,
                        activityDate: $dateString,
                        sessionsPerDayMin: (int) $profile->sessions_per_day_min,
                        sessionsPerDayMax: (int) $profile->sessions_per_day_max,
                    );
                    $planned += $target;

                    $started = (int) SyntheticUserSession::query()
                        ->where('user_id', $user->id)
                        ->whereDate('activity_date', $dateString)
                        ->whereNotNull('daily_session_index')
                        ->count();

                    $remaining += max(0, $target - $started);
                }
            });

        $startedTotal = (int) SyntheticUserSession::query()
            ->whereDate('activity_date', $dateString)
            ->count();

        return [
            'planned_sessions_today' => $planned,
            'started_sessions_today' => $startedTotal,
            'remaining_sessions_today' => $remaining,
            'daily_plan_exhausted' => $planned > 0 && $remaining === 0,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectArchetypeRanges(): array
    {
        $ranges = [];
        foreach (SyntheticDecisionProfiles::ALLOWED as $archetype) {
            $query = SyntheticUserProfile::query()
                ->where('is_enabled', true)
                ->where('decision_profile', $archetype);

            if (! $query->exists()) {
                continue;
            }

            $ranges[$archetype] = [
                'count' => (int) $query->count(),
                'sessions_per_day' => [
                    'min' => (int) $query->min('sessions_per_day_min'),
                    'max' => (int) $query->max('sessions_per_day_max'),
                ],
                'actions_per_session' => [
                    'min' => (int) $query->min('actions_per_session_min'),
                    'max' => (int) $query->max('actions_per_session_max'),
                ],
                'delay_seconds' => [
                    'min' => (int) $query->min('delay_seconds_min'),
                    'max' => (int) $query->max('delay_seconds_max'),
                ],
                'skip_probability' => [
                    'min' => (float) $query->min('skip_probability'),
                    'max' => (float) $query->max('skip_probability'),
                ],
                'decision_accuracy' => [
                    'min' => (float) $query->min('decision_accuracy'),
                    'max' => (float) $query->max('decision_accuracy'),
                ],
                'noise_level' => [
                    'min' => (float) $query->min('noise_level'),
                    'max' => (float) $query->max('noise_level'),
                ],
            ];
        }

        return $ranges;
    }

    private function resolveHealth(
        bool $automationEnabled,
        int $enabledProfiles,
        int $overdue5,
        int $overdue15,
        int $worldSessionsTotal,
        int $syntheticVotes,
        int $syntheticUsersTotal,
    ): string {
        if (! $automationEnabled) {
            return 'inactive';
        }

        if ($overdue15 > 0) {
            return 'critical';
        }

        if ($overdue5 > 0) {
            return 'warning';
        }

        if ($syntheticUsersTotal === 0 && $enabledProfiles === 0 && $worldSessionsTotal === 0 && $syntheticVotes === 0) {
            return 'no_data';
        }

        if ($enabledProfiles === 0 && $worldSessionsTotal === 0 && $syntheticVotes === 0) {
            return 'no_data';
        }

        return 'healthy';
    }

    /**
     * @return array{expert: int, casual: int, noisy: int, invalid_unknown: int}
     */
    private function emptyProfileBucket(): array
    {
        return [
            'expert' => 0,
            'casual' => 0,
            'noisy' => 0,
            'invalid_unknown' => 0,
        ];
    }

    /**
     * @param array{expert: int, casual: int, noisy: int, invalid_unknown: int} $bucket
     */
    private function addToProfileBucket(array &$bucket, mixed $profile, int $count): void
    {
        $key = is_string($profile) ? $profile : '';
        if (isset($bucket[$key]) && $key !== 'invalid_unknown') {
            $bucket[$key] += $count;
        } else {
            $bucket['invalid_unknown'] += $count;
        }
    }

    private function toIsoOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toIso8601String();
    }
}
