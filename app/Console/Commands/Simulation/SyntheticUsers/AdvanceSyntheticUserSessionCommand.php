<?php

namespace App\Console\Commands\Simulation\SyntheticUsers;

use App\Simulation\Synthetic\AdvanceSyntheticUserSessionAction;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class AdvanceSyntheticUserSessionCommand extends Command
{
    protected $signature = 'zcout:synthetic-users:advance-session
        {--session-id= : Existing synthetic user session id}';

    protected $description = 'Execute exactly one due action for a persistent synthetic user session';

    public function handle(AdvanceSyntheticUserSessionAction $advanceSyntheticUserSessionAction): int
    {
        $sessionId = $this->option('session-id');
        if ($sessionId === null || $sessionId === '') {
            $this->error('The --session-id option is required.');

            return self::FAILURE;
        }

        if (! is_numeric($sessionId) || (int) $sessionId <= 0) {
            $this->error('The --session-id option must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $advanceSyntheticUserSessionAction->execute((int) $sessionId);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Session aborted: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $action = $result->action;
        $session = $result->session;

        $this->line($action->formatLine());
        $this->newLine();
        $this->line('Status: ' . $session->status);
        $this->line('Completed actions: ' . $session->completed_actions);
        $this->line('Planned actions: ' . $session->planned_actions);
        $this->line('Next action at: ' . ($session->next_action_at?->toIso8601String() ?? 'null'));
        if ($session->completed_at !== null) {
            $this->line('Completed at: ' . $session->completed_at->toIso8601String());
        }

        // Align with run-session: domain action failures yield FAILURE while leaving session active when applicable.
        return $action->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
