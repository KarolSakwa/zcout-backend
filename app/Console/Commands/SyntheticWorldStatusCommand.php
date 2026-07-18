<?php

namespace App\Console\Commands;

use App\Simulation\Synthetic\GetSyntheticWorldStatusAction;
use App\Simulation\Synthetic\SyntheticWorldStatus;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class SyntheticWorldStatusCommand extends Command
{
    protected $signature = 'zcout:synthetic-world:status
        {--date= : Report date in YYYY-MM-DD (app timezone)}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Show a read-only Synthetic World soak-test status report';

    public function handle(GetSyntheticWorldStatusAction $getSyntheticWorldStatusAction): int
    {
        $date = $this->option('date');
        if ($date === '') {
            $date = null;
        }

        $includeDetails = $this->output->isVerbose();

        try {
            $status = $getSyntheticWorldStatusAction->execute(
                date: is_string($date) ? $date : null,
                includeDetails: $includeDetails,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Synthetic world status failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($status->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderText($status, $includeDetails);

        return self::SUCCESS;
    }

    private function renderText(SyntheticWorldStatus $status, bool $includeDetails): void
    {
        $this->line('Synthetic World Status');
        $this->line('Date: '.$status->date);
        $this->line('Time: '.$status->time);
        $this->line('Timezone: '.$status->timezone);
        $this->line('Automation: '.($status->automation_enabled ? 'enabled' : 'disabled'));
        $this->line('Health: '.$status->health);
        $this->newLine();

        $this->line('Users');
        $this->line('  Synthetic total: '.$status->synthetic_users_total);
        $this->line('  Managed pool: '.$status->managed_pool_users);
        $this->line('  Manual synthetic: '.$status->manual_synthetic_users);
        $this->line('  Enabled profiles: '.$status->enabled_profiles);
        $this->line('  Disabled profiles: '.$status->disabled_profiles);
        $this->line('  Missing profiles: '.$status->missing_profiles);
        $this->line('  Invalid profiles: '.$status->invalid_profiles);
        $this->newLine();

        $this->line('Profiles');
        $this->line('  Expert: '.$status->profiles['expert']);
        $this->line('  Casual: '.$status->profiles['casual']);
        $this->line('  Noisy: '.$status->profiles['noisy']);
        $this->line('  Invalid/unknown: '.$status->profiles['invalid_unknown']);
        $this->newLine();

        $world = $status->worldSessions;
        $this->line('World sessions');
        $this->line('  Total: '.$world['total']);
        $this->line('  Active: '.$world['active']);
        $this->line('  Completed: '.$world['completed']);
        $this->line('  Failed: '.$world['failed']);
        $this->line('  Planned actions: '.$world['planned_actions_sum']);
        $this->line('  Completed actions: '.$world['completed_actions_sum']);
        $this->line('  Completion: '.$this->formatRatioPercent($world['completion_ratio']));
        $this->newLine();

        $manual = $status->manualSessions;
        $this->line('Manual sessions');
        $this->line('  Started: '.$manual['started']);
        $this->line('  Active: '.$manual['active']);
        $this->line('  Completed: '.$manual['completed']);
        $this->line('  Failed: '.$manual['failed']);
        $this->newLine();

        $execution = $status->execution;
        $this->line('Execution');
        $this->line('  Due now: '.$execution['due_now']);
        $this->line('  Overdue >1m: '.$execution['overdue_1_min']);
        $this->line('  Overdue >5m: '.$execution['overdue_5_min']);
        $this->line('  Overdue >15m: '.$execution['overdue_15_min']);
        $this->line('  Oldest overdue: '.$this->formatOldestOverdue($execution));
        $this->newLine();

        $activity = $status->activity;
        $this->line('Activity');
        $this->line('  Votes: '.$activity['synthetic_votes']);
        $this->line('  Skips: '.$activity['synthetic_skips']);
        $this->line('  Unique voters: '.$activity['unique_synthetic_voters']);
        $this->line('  Average votes / active user: '.$this->formatNullableNumber($activity['average_votes_per_active_synthetic_user']));
        $this->line('  Latest vote: '.($activity['latest_synthetic_vote_at'] ?? 'none'));
        $this->newLine();

        $this->line('Current session last-action states');
        $this->line('  ok: '.$status->lastActionStates['ok']);
        $this->line('  failure: '.$status->lastActionStates['failure']);
        $this->line('  no_duel_available: '.$status->lastActionStates['no_duel_available']);
        $this->line('  unexpected_error: '.$status->lastActionStates['unexpected_error']);
        $this->line('  missing_live_rating: '.$status->lastActionStates['missing_live_rating']);
        $this->line('  duplicate_vote: '.$status->lastActionStates['duplicate_vote']);
        $this->newLine();

        $failures = $status->failures;
        $this->line('Failures');
        $this->line('  Failed sessions today: '.$failures['failed_sessions_today']);
        $this->line('  Failed sessions total: '.$failures['failed_sessions_total']);
        $this->line('  Latest reason: '.($failures['latest_failed_reason'] ?? 'none'));
        $this->newLine();

        $locks = $status->locks;
        $this->line('Locks');
        $this->line('  Synthetic locks: '.$locks['synthetic_locks_total']);
        $this->line('  Stale >1m: '.$locks['stale_synthetic_locks_1_min']);
        $this->line('  Stale >5m: '.$locks['stale_synthetic_locks_5_min']);
        $this->newLine();

        $this->line('Warnings');
        if ($status->warnings === []) {
            $this->line('  none');
        } else {
            foreach ($status->warnings as $warning) {
                $this->line('  - '.$warning['code']);
            }
        }

        if ($includeDetails) {
            $this->newLine();
            $this->renderDetails($status);
        }
    }

    private function renderDetails(SyntheticWorldStatus $status): void
    {
        $this->line('Details (verbose, capped at 20 each)');

        $this->line('Invalid profiles:');
        if ($status->invalidProfileDetails === []) {
            $this->line('  none');
        } else {
            foreach ($status->invalidProfileDetails as $row) {
                $this->line(sprintf(
                    '  user_id=%d profile=%s enabled=%s',
                    $row['user_id'],
                    $row['profile'],
                    $row['is_enabled'] ? 'true' : 'false',
                ));
            }
        }

        $this->line('Failed sessions:');
        if ($status->failedSessionDetails === []) {
            $this->line('  none');
        } else {
            foreach ($status->failedSessionDetails as $row) {
                $this->line(sprintf(
                    '  session_id=%d user_id=%d reason=%s completed=%d/%d',
                    $row['session_id'],
                    $row['user_id'],
                    $row['reason'],
                    $row['completed_actions'],
                    $row['planned_actions'],
                ));
            }
        }

        $this->line('Overdue sessions:');
        if ($status->overdueSessionDetails === []) {
            $this->line('  none');
        } else {
            foreach ($status->overdueSessionDetails as $row) {
                $this->line(sprintf(
                    '  session_id=%d user_id=%d next_action_at=%s completed=%d/%d',
                    $row['session_id'],
                    $row['user_id'],
                    $row['next_action_at'] ?? 'null',
                    $row['completed_actions'],
                    $row['planned_actions'],
                ));
            }
        }

        $this->line('Stale locks:');
        if ($status->staleLockDetails === []) {
            $this->line('  none');
        } else {
            foreach ($status->staleLockDetails as $row) {
                $this->line(sprintf(
                    '  user_id=%d duel_id=%d age_seconds=%d',
                    $row['user_id'],
                    $row['duel_id'],
                    $row['age_seconds'],
                ));
            }
        }
    }

    /**
     * @param array{oldest_overdue_session_id: int|null, oldest_overdue_next_action_at: string|null, oldest_overdue_seconds: int|null} $execution
     */
    private function formatOldestOverdue(array $execution): string
    {
        if ($execution['oldest_overdue_session_id'] === null) {
            return 'none';
        }

        return sprintf(
            'session=%d at %s (%ds)',
            $execution['oldest_overdue_session_id'],
            $execution['oldest_overdue_next_action_at'] ?? 'null',
            $execution['oldest_overdue_seconds'] ?? 0,
        );
    }

    private function formatRatioPercent(?float $ratio): string
    {
        if ($ratio === null) {
            return 'n/a';
        }

        return number_format($ratio * 100, 2).'%';
    }

    private function formatNullableNumber(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return number_format($value, 2, '.', '');
    }
}
