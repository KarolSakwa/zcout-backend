<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticUserSession;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TickSyntheticWorldAction
{
    public const DEFAULT_USER_LIMIT = 100;

    public const DEFAULT_SESSION_LIMIT = 100;

    public function __construct(
        private readonly SyntheticDailyActivityPlanner $planner,
        private readonly StartSyntheticUserSessionAction $startSyntheticUserSessionAction,
        private readonly AdvanceSyntheticUserSessionAction $advanceSyntheticUserSessionAction,
        private readonly ValidateSyntheticUserProfile $validateSyntheticUserProfile,
    ) {
    }

    public function execute(?int $userLimit = null, ?int $sessionLimit = null): SyntheticWorldTickResult
    {
        $result = new SyntheticWorldTickResult();
        $userLimit = max(1, $userLimit ?? self::DEFAULT_USER_LIMIT);
        $sessionLimit = max(1, $sessionLimit ?? self::DEFAULT_SESSION_LIMIT);

        $now = now();
        $activityDate = $now->copy()->timezone((string) config('app.timezone', 'UTC'))->toDateString();

        $this->planAndStartSessions($result, $activityDate, $userLimit, $now);
        $this->advanceDueSessions($result, $sessionLimit);

        return $result;
    }

    private function planAndStartSessions(
        SyntheticWorldTickResult $result,
        string $activityDate,
        int $userLimit,
        mixed $now,
    ): void {
        $usersConsidered = 0;

        User::query()
            ->where('is_synthetic', true)
            ->whereHas('syntheticProfile', static function ($query): void {
                $query->where('is_enabled', true);
            })
            ->with('syntheticProfile')
            ->orderBy('id')
            ->chunkById(50, function ($users) use (
                &$usersConsidered,
                $userLimit,
                $result,
                $activityDate,
                $now,
            ): bool {
                foreach ($users as $user) {
                    if ($usersConsidered >= $userLimit) {
                        return false;
                    }

                    $usersConsidered++;
                    $result->usersConsidered++;

                    try {
                        $this->maybeStartWorldSessionForUser($user, $activityDate, $now, $result);
                    } catch (Throwable $exception) {
                        $result->errors++;
                        Log::error('synthetic.world.unexpected_error', [
                            'phase' => 'planning',
                            'user_id' => $user->id,
                            'session_id' => null,
                            'exception' => $exception::class,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }

                return $usersConsidered < $userLimit;
            });
    }

    private function maybeStartWorldSessionForUser(
        User $user,
        string $activityDate,
        mixed $now,
        SyntheticWorldTickResult $result,
    ): void {
        $profile = $user->syntheticProfile;
        if ($profile === null) {
            $result->errors++;
            Log::warning('synthetic.world.invalid_profile', [
                'user_id' => $user->id,
                'reason' => 'missing_profile',
            ]);

            return;
        }

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
        } catch (DomainException $exception) {
            $result->errors++;
            Log::warning('synthetic.world.invalid_profile', [
                'user_id' => $user->id,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $target = $this->planner->targetSessionsToday(
            userId: (int) $user->id,
            activityDate: $activityDate,
            sessionsPerDayMin: (int) $profile->sessions_per_day_min,
            sessionsPerDayMax: (int) $profile->sessions_per_day_max,
        );

        if ($target === 0) {
            $result->inactiveUsersToday++;

            return;
        }

        $existingIndexes = SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->whereDate('activity_date', $activityDate)
            ->whereNotNull('daily_session_index')
            ->pluck('daily_session_index')
            ->map(static fn ($index): int => (int) $index)
            ->all();

        $nextIndex = null;
        for ($index = 1; $index <= $target; $index++) {
            if (! in_array($index, $existingIndexes, true)) {
                $nextIndex = $index;
                break;
            }
        }

        if ($nextIndex === null) {
            return;
        }

        $scheduledStartAt = $this->planner->scheduledStartAt(
            userId: (int) $user->id,
            activityDate: $activityDate,
            dailySessionIndex: $nextIndex,
            targetSessionsToday: $target,
        );

        if ($scheduledStartAt->gt($now)) {
            return;
        }

        $hasActiveSession = SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->exists();

        if ($hasActiveSession) {
            return;
        }

        $sessionSeed = $this->planner->sessionSeed(
            userId: (int) $user->id,
            activityDate: $activityDate,
            dailySessionIndex: $nextIndex,
        );

        try {
            $this->startSyntheticUserSessionAction->execute($user, [
                'activity_date' => $activityDate,
                'daily_session_index' => $nextIndex,
                'scheduled_start_at' => $scheduledStartAt,
                'session_seed' => $sessionSeed,
            ]);
            $result->sessionsStarted++;
        } catch (UniqueConstraintViolationException) {
            $result->sessionStartConflicts++;
        } catch (DomainException $exception) {
            $result->errors++;
            Log::warning('synthetic.world.session_start_rejected', [
                'phase' => 'start',
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            if ($this->isUniqueViolation($exception)) {
                $result->sessionStartConflicts++;

                return;
            }

            $result->errors++;
            Log::error('synthetic.world.unexpected_error', [
                'phase' => 'start',
                'user_id' => $user->id,
                'session_id' => null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function advanceDueSessions(SyntheticWorldTickResult $result, int $sessionLimit): void
    {
        $dueSessionIds = SyntheticUserSession::query()
            ->where('status', SyntheticSessionStatuses::ACTIVE)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->orderBy('next_action_at')
            ->orderBy('id')
            ->limit($sessionLimit)
            ->pluck('id')
            ->all();

        $result->dueSessionsFound = count($dueSessionIds);

        foreach ($dueSessionIds as $sessionId) {
            try {
                $advanceResult = $this->advanceSyntheticUserSessionAction->execute((int) $sessionId);
                $result->sessionsAdvanced++;

                $action = $advanceResult->action;
                if ($action->status === 'ok' && $action->decision === 'vote') {
                    $result->votes++;
                } elseif ($action->status === 'ok' && $action->decision === 'skip') {
                    $result->skips++;
                } elseif ($action->status === 'failure') {
                    $result->actionFailures++;
                }

                $session = $advanceResult->session;
                if ($session->isCompleted()) {
                    $result->completedSessions++;
                } elseif ($session->isFailed()) {
                    $result->failedSessions++;
                }
            } catch (DomainException) {
                // Expected races: completed/failed/not-due after concurrent work.
                continue;
            } catch (Throwable $exception) {
                $result->errors++;
                $result->failedSessions++;
                Log::error('synthetic.world.unexpected_error', [
                    'phase' => 'advance',
                    'user_id' => null,
                    'session_id' => (int) $sessionId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function isUniqueViolation(Throwable $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'synthetic_user_sessions_daily_unique')
            || str_contains($message, 'Unique violation')
            || str_contains($message, 'duplicate key');
    }
}
