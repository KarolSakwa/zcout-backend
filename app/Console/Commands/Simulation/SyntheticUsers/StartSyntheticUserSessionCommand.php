<?php

namespace App\Console\Commands\Simulation\SyntheticUsers;

use App\Models\User;
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use DomainException;
use Illuminate\Console\Command;

final class StartSyntheticUserSessionCommand extends Command
{
    protected $signature = 'zcout:synthetic-users:start-session
        {--user-id= : Existing synthetic user id}';

    protected $description = 'Create a persistent synthetic user session from the user profile';

    public function handle(StartSyntheticUserSessionAction $startSyntheticUserSessionAction): int
    {
        $userId = $this->option('user-id');
        if ($userId === null || $userId === '') {
            $this->error('The --user-id option is required.');

            return self::FAILURE;
        }

        if (! is_numeric($userId) || (int) $userId <= 0) {
            $this->error('The --user-id option must be a positive integer.');

            return self::FAILURE;
        }

        $user = User::query()->with('syntheticProfile')->find((int) $userId);
        if ($user === null) {
            $this->error(sprintf('User [%d] was not found.', (int) $userId));

            return self::FAILURE;
        }

        try {
            $session = $startSyntheticUserSessionAction->execute($user);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $profile = $user->syntheticProfile;

        $this->line('Session started');
        $this->line('Session ID: ' . $session->id);
        $this->line('User: ' . $session->user_id);
        $this->line('Profile: ' . ($profile?->decision_profile ?? '-'));
        $this->line('Planned actions: ' . $session->planned_actions);
        $this->line('Completed actions: ' . $session->completed_actions);
        $this->line('Next action at: ' . $session->next_action_at?->toIso8601String());
        $this->line('Session seed: ' . $session->session_seed);

        return self::SUCCESS;
    }
}
