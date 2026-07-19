<?php

namespace App\Simulation\Synthetic;

use App\Actions\AuthenticatedVoterLockKey;
use App\Models\SyntheticUserProfile;
use App\Models\SyntheticUserSession;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ConfigureSyntheticWorldAction
{
    /**
     * @param  array{
     *     sessions_min?: int|null,
     *     sessions_max?: int|null,
     *     actions_min?: int|null,
     *     actions_max?: int|null,
     *     delay_min?: int|null,
     *     delay_max?: int|null,
     *     skip_probability?: float|null,
     *     decision_accuracy?: float|null,
     *     noise_level?: float|null,
     *     pool?: string|null,
     *     archetype?: string|null,
     *     all?: bool,
     *     dry_run?: bool,
     *     reset_daily_plan?: bool
     * }  $options
     */
    public function execute(array $options): ConfigureSyntheticWorldResult
    {
        $all = (bool) ($options['all'] ?? false);
        $archetype = $options['archetype'] ?? null;
        $pool = $options['pool'] ?? null;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $resetDailyPlan = (bool) ($options['reset_daily_plan'] ?? false);

        $this->assertSelector($all, $archetype, $pool);

        if ($pool !== null) {
            $this->assertPoolExists($pool);
        }

        if ($archetype !== null && ! SyntheticDecisionProfiles::isAllowed($archetype)) {
            throw new DomainException(
                'Invalid --archetype. Allowed: '.SyntheticDecisionProfiles::listForMessage().'.',
            );
        }

        $changes = $this->extractChanges($options);
        if ($changes === [] && ! $resetDailyPlan) {
            throw new DomainException(
                'No configuration changes provided. Pass at least one of: --sessions-min/max, --actions-min/max, --delay-min/max, --skip-probability, --decision-accuracy, --noise-level, or --reset-daily-plan.',
            );
        }

        $profiles = $this->selectProfiles($all, $archetype, $pool);
        if ($profiles->isEmpty()) {
            throw new DomainException('No matching enabled synthetic profiles found for the given selector.');
        }

        $before = $this->snapshotProfiles($profiles);
        $warnings = [];
        $cancelled = 0;

        if ($resetDailyPlan) {
            $userIds = $profiles->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
            $activeCount = $this->activeWorldSessionsQuery($userIds)->count();
            if ($activeCount > 0) {
                $lockedUserIds = $this->activeVoterLockUserIds($userIds);
                if ($lockedUserIds !== []) {
                    throw new DomainException(sprintf(
                        'Cannot safely reset daily plan: %d selected user(s) hold an active voter duel lock (possible in-flight vote). Wait for locks to clear, then retry. Locked user ids: %s.',
                        count($lockedUserIds),
                        implode(', ', array_slice($lockedUserIds, 0, 20)),
                    ));
                }
            }

            if ($activeCount > 0 && ! $dryRun) {
                $cancel = app(CancelActiveSyntheticSessionsAction::class)->execute(
                    $this->activeWorldSessionsQuery($userIds),
                    CancelActiveSyntheticSessionsAction::REASON_DAILY_PLAN_RESET,
                );
                $cancelled = $cancel['cancelled'];
                $warnings[] = sprintf('Cancelled %d active world session(s) for daily plan reset.', $cancelled);
            } elseif ($activeCount > 0 && $dryRun) {
                $warnings[] = sprintf('Dry-run: would cancel %d active world session(s) for daily plan reset.', $activeCount);
            }
        }

        $updated = 0;
        if ($changes !== [] && ! $dryRun) {
            DB::transaction(function () use ($profiles, $changes, &$updated): void {
                foreach ($profiles as $profile) {
                    $merged = array_merge($profile->only([
                        'decision_profile',
                        'sessions_per_day_min',
                        'sessions_per_day_max',
                        'actions_per_session_min',
                        'actions_per_session_max',
                        'delay_seconds_min',
                        'delay_seconds_max',
                        'skip_probability',
                        'decision_accuracy',
                        'noise_level',
                        'is_enabled',
                    ]), $changes);

                    app(ValidateSyntheticUserProfile::class)->validate($merged);
                    $profile->fill($changes);
                    $profile->save();
                    $updated++;
                }
            });
        } elseif ($changes !== []) {
            foreach ($profiles as $profile) {
                $merged = array_merge($profile->only([
                    'decision_profile',
                    'sessions_per_day_min',
                    'sessions_per_day_max',
                    'actions_per_session_min',
                    'actions_per_session_max',
                    'delay_seconds_min',
                    'delay_seconds_max',
                    'skip_probability',
                    'decision_accuracy',
                    'noise_level',
                    'is_enabled',
                ]), $changes);
                app(ValidateSyntheticUserProfile::class)->validate($merged);
            }
            $updated = $profiles->count();
        }

        $after = $dryRun
            ? $this->projectDryRun($before, $changes)
            : $this->snapshotProfiles(
                SyntheticUserProfile::query()->whereIn('id', $before->keys())->orderBy('id')->get(),
            );

        return new ConfigureSyntheticWorldResult(
            selector: $this->selectorLabel($all, $archetype, $pool),
            profileCount: $before->count(),
            updatedCount: $updated,
            dryRun: $dryRun,
            resetDailyPlan: $resetDailyPlan,
            cancelledSessions: $cancelled,
            changes: $changes,
            before: $before->all(),
            after: $after->all(),
            warnings: $warnings,
            activeSessions: (int) SyntheticUserSession::query()
                ->whereIn('user_id', $before->pluck('user_id'))
                ->where('status', SyntheticSessionStatuses::ACTIVE)
                ->count(),
        );
    }

    private function assertSelector(bool $all, ?string $archetype, ?string $pool): void
    {
        if (! $all && $archetype === null && $pool === null) {
            throw new DomainException(
                'Selector required. Pass --all, --archetype=expert|casual|noisy, and/or --pool=NAME.',
            );
        }

        if ($all && $archetype !== null) {
            throw new DomainException('Conflicting filters: --all cannot be combined with --archetype.');
        }
    }

    private function assertPoolExists(string $pool): void
    {
        app(SyntheticPoolIdentity::class)->assertValidPoolKey($pool);

        $exists = User::query()
            ->where('is_synthetic', true)
            ->where('synthetic_pool_key', $pool)
            ->exists();

        if (! $exists) {
            throw new DomainException(sprintf('Managed pool [%s] does not exist.', $pool));
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, int|float>
     */
    private function extractChanges(array $options): array
    {
        $map = [
            'sessions_min' => 'sessions_per_day_min',
            'sessions_max' => 'sessions_per_day_max',
            'actions_min' => 'actions_per_session_min',
            'actions_max' => 'actions_per_session_max',
            'delay_min' => 'delay_seconds_min',
            'delay_max' => 'delay_seconds_max',
            'skip_probability' => 'skip_probability',
            'decision_accuracy' => 'decision_accuracy',
            'noise_level' => 'noise_level',
        ];

        $changes = [];
        foreach ($map as $option => $column) {
            if (! array_key_exists($option, $options) || $options[$option] === null) {
                continue;
            }

            $value = $options[$option];
            if (in_array($column, ['skip_probability', 'decision_accuracy', 'noise_level'], true)) {
                if (! is_numeric($value)) {
                    throw new DomainException(sprintf('Invalid --%s. Expected a number between 0 and 1.', $option));
                }
                $float = (float) $value;
                if ($float < 0.0 || $float > 1.0) {
                    throw new DomainException(sprintf('Invalid --%s. Expected a number between 0 and 1.', $option));
                }
                $changes[$column] = $float;
                continue;
            }

            if (! is_numeric($value) || (int) $value != $value) {
                throw new DomainException(sprintf('Invalid --%s. Expected an integer.', $option));
            }
            $int = (int) $value;
            if (str_starts_with($column, 'actions_') && $int < 1) {
                throw new DomainException(sprintf('Invalid --%s. Expected an integer >= 1.', $option));
            }
            if (! str_starts_with($column, 'actions_') && $int < 0) {
                throw new DomainException(sprintf('Invalid --%s. Expected an integer >= 0.', $option));
            }
            $changes[$column] = $int;
        }

        return $changes;
    }

    /**
     * @return \Illuminate\Support\Collection<int, SyntheticUserProfile>
     */
    private function selectProfiles(bool $all, ?string $archetype, ?string $pool)
    {
        $query = SyntheticUserProfile::query()
            ->where('is_enabled', true)
            ->whereHas('user', function ($userQuery) use ($pool): void {
                $userQuery->where('is_synthetic', true);
                if ($pool !== null) {
                    $userQuery->where('synthetic_pool_key', $pool);
                }
            })
            ->with('user')
            ->orderBy('id');

        if (! $all && $archetype !== null) {
            $query->where('decision_profile', $archetype);
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return \Illuminate\Database\Eloquent\Builder<SyntheticUserSession>
     */
    private function activeWorldSessionsQuery(array $userIds)
    {
        return SyntheticUserSession::query()
            ->whereIn('user_id', $userIds)
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->whereNotNull('activity_date');
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function activeVoterLockUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $hashes = array_map(
            static fn (int $userId): string => AuthenticatedVoterLockKey::forUserId($userId),
            $userIds,
        );

        $lockedHashes = DB::table('voter_duel_locks')
            ->whereIn('voter_hash', $hashes)
            ->pluck('voter_hash')
            ->all();

        if ($lockedHashes === []) {
            return [];
        }

        $locked = [];
        foreach ($userIds as $userId) {
            if (in_array(AuthenticatedVoterLockKey::forUserId($userId), $lockedHashes, true)) {
                $locked[] = $userId;
            }
        }

        return $locked;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SyntheticUserProfile>  $profiles
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function snapshotProfiles($profiles)
    {
        return $profiles->mapWithKeys(function (SyntheticUserProfile $profile): array {
            return [
                (int) $profile->id => [
                    'id' => (int) $profile->id,
                    'user_id' => (int) $profile->user_id,
                    'decision_profile' => (string) $profile->decision_profile,
                    'sessions_per_day_min' => (int) $profile->sessions_per_day_min,
                    'sessions_per_day_max' => (int) $profile->sessions_per_day_max,
                    'actions_per_session_min' => (int) $profile->actions_per_session_min,
                    'actions_per_session_max' => (int) $profile->actions_per_session_max,
                    'delay_seconds_min' => (int) $profile->delay_seconds_min,
                    'delay_seconds_max' => (int) $profile->delay_seconds_max,
                    'skip_probability' => (float) $profile->skip_probability,
                    'decision_accuracy' => (float) $profile->decision_accuracy,
                    'noise_level' => (float) $profile->noise_level,
                ],
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $before
     * @param  array<string, int|float>  $changes
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function projectDryRun($before, array $changes)
    {
        return $before->map(static function (array $row) use ($changes): array {
            return array_merge($row, $changes);
        });
    }

    private function selectorLabel(bool $all, ?string $archetype, ?string $pool): string
    {
        $parts = [];
        if ($all) {
            $parts[] = 'all';
        }
        if ($archetype !== null) {
            $parts[] = 'archetype='.$archetype;
        }
        if ($pool !== null) {
            $parts[] = 'pool='.$pool;
        }

        return implode(', ', $parts);
    }
}
