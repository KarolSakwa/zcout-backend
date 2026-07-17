<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Simulation\Synthetic\RunSyntheticUserSessionAction;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RunSyntheticUserSessionCommand extends Command
{
    private const PROFILES = ['expert', 'casual', 'noisy'];

    protected $signature = 'zcout:synthetic-users:run-session
        {--user-id= : Existing user id}
        {--actions=5 : Number of duel actions to perform}
        {--profile=casual : Behavior profile (expert, casual, noisy)}';

    protected $description = 'Run a manual synthetic user session through the production duel flow';

    public function handle(RunSyntheticUserSessionAction $runSyntheticUserSessionAction): int
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

        $actions = (int) $this->option('actions');
        if ($actions <= 0) {
            $this->error('The --actions option must be a positive integer.');

            return self::FAILURE;
        }

        $profile = (string) $this->option('profile');
        if (! in_array($profile, self::PROFILES, true)) {
            $this->error('The --profile option must be one of: ' . implode(', ', self::PROFILES) . '.');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $userId);
        if ($user === null) {
            $this->error(sprintf('User [%d] was not found.', (int) $userId));

            return self::FAILURE;
        }

        $sessionId = (string) Str::uuid();

        $this->line('Synthetic session started');
        $this->line('User: ' . $user->id);
        $this->line('Profile: ' . $profile);
        $this->line('Planned actions: ' . $actions);
        $this->line('Session id: ' . $sessionId);
        $this->newLine();

        try {
            $summary = $runSyntheticUserSessionAction->execute(
                user: $user,
                profile: $profile,
                actions: $actions,
                sessionId: $sessionId,
                onAction: function ($result): void {
                    $this->line($result->formatLine());
                },
            );
        } catch (\Throwable $exception) {
            $this->newLine();
            $this->error(sprintf(
                'Session aborted: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Session completed');
        $this->line('Votes: ' . $summary->votes);
        $this->line('Skips: ' . $summary->skips);
        $this->line('Failures: ' . $summary->failures);

        return $summary->failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
