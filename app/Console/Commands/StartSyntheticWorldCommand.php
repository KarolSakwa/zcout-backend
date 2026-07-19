<?php

namespace App\Console\Commands;

use App\Simulation\Synthetic\StartSyntheticWorldAction;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class StartSyntheticWorldCommand extends Command
{
    protected $signature = 'zcout:synthetic-world:start
        {--clear-stale-mutex : Clear schedule mutex only when heartbeat proves it is stale}
        {--reset-daily-plan : Cancel active world sessions so new daily capacity can start}';

    protected $description = 'Enable runtime Synthetic World automation and run a control tick';

    public function handle(StartSyntheticWorldAction $action): int
    {
        try {
            $result = $action->execute(
                clearStaleMutex: (bool) $this->option('clear-stale-mutex'),
                resetDailyPlan: (bool) $this->option('reset-daily-plan'),
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Synthetic World start');
        $this->line('Environment automation: enabled');
        $this->line('Runtime automation: '.($result['runtime']->runtime_enabled ? 'running' : 'paused'));
        if ($result['mutex'] !== null) {
            $this->line('Mutex: '.$result['mutex']['reason']);
        }

        $tick = $result['tick'];
        if ($tick !== null) {
            $this->newLine();
            $this->line('Control tick');
            $this->line('  Sessions started: '.$tick->sessionsStarted);
            $this->line('  Sessions advanced: '.$tick->sessionsAdvanced);
            $this->line('  Votes: '.$tick->votes);
            $this->line('  Skips: '.$tick->skips);
            $this->line('  Errors: '.$tick->errors);
        }

        $status = $result['status'];
        $this->newLine();
        $this->line('Status snapshot');
        $this->line('  Health: '.$status->health);
        $this->line('  Active sessions: '.$status->worldSessions['active']);
        $this->line('  Due now: '.$status->execution['due_now']);
        $this->line('  Overdue >1m: '.$status->execution['overdue_1_min']);
        $this->line('  Votes today: '.$status->activity['synthetic_votes']);
        $this->line('  Skips today: '.$status->activity['synthetic_skips']);
        $this->line('  Latest vote: '.($status->activity['latest_synthetic_vote_at'] ?? 'none'));

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->line('Warnings');
            foreach ($result['warnings'] as $warning) {
                $this->line('  - '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
