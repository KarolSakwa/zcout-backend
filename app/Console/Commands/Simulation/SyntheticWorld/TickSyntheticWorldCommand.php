<?php

namespace App\Console\Commands\Simulation\SyntheticWorld;

use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Illuminate\Console\Command;
use Throwable;

final class TickSyntheticWorldCommand extends Command
{
    protected $signature = 'zcout:synthetic-world:tick
        {--user-limit= : Max synthetic users to consider in this tick}
        {--session-limit= : Max due sessions to advance in this tick}';

    protected $description = 'Run one manual synthetic world tick: plan daily sessions and advance due actions';

    public function handle(TickSyntheticWorldAction $tickSyntheticWorldAction): int
    {
        $userLimit = $this->parsePositiveOption('user-limit');
        if ($userLimit === false) {
            return self::FAILURE;
        }

        $sessionLimit = $this->parsePositiveOption('session-limit');
        if ($sessionLimit === false) {
            return self::FAILURE;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        $this->line('Synthetic world tick started');
        $this->line('Time: ' . now()->timezone($timezone)->toIso8601String());
        $this->line('Timezone: ' . $timezone);
        $this->newLine();

        try {
            $result = $tickSyntheticWorldAction->execute(
                userLimit: $userLimit,
                sessionLimit: $sessionLimit,
            );
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Synthetic world tick failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->line('Users considered: ' . $result->usersConsidered);
        $this->line('Inactive today: ' . $result->inactiveUsersToday);
        $this->line('Sessions started: ' . $result->sessionsStarted);
        $this->line('Start conflicts: ' . $result->sessionStartConflicts);
        $this->line('Due sessions found: ' . $result->dueSessionsFound);
        $this->line('Sessions advanced: ' . $result->sessionsAdvanced);
        $this->line('Votes: ' . $result->votes);
        $this->line('Skips: ' . $result->skips);
        $this->line('Action failures: ' . $result->actionFailures);
        $this->line('Completed sessions: ' . $result->completedSessions);
        $this->line('Failed sessions: ' . $result->failedSessions);
        $this->line('Errors: ' . $result->errors);
        $this->newLine();
        $this->line('Synthetic world tick completed');

        return self::SUCCESS;
    }

    /**
     * @return int|null|false
     */
    private function parsePositiveOption(string $name): int|null|false
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            $this->error(sprintf('The --%s option must be a positive integer.', $name));

            return false;
        }

        return (int) $value;
    }
}
