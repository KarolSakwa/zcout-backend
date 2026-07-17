<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Simulation\Synthetic\RunSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\ValidateSyntheticUserProfile;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RunSyntheticUserSessionCommand extends Command
{
    protected $signature = 'zcout:synthetic-users:run-session
        {--user-id= : Existing synthetic user id}
        {--actions=5 : Number of duel actions to perform}';

    protected $description = 'Run a manual synthetic user session through the production duel flow';

    public function handle(
        RunSyntheticUserSessionAction $runSyntheticUserSessionAction,
        ValidateSyntheticUserProfile $validateSyntheticUserProfile,
    ): int {
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

        $user = User::query()->with('syntheticProfile')->find((int) $userId);
        if ($user === null) {
            $this->error(sprintf('User [%d] was not found.', (int) $userId));

            return self::FAILURE;
        }

        if (! $user->is_synthetic) {
            $this->error(sprintf('User [%d] is not a synthetic user.', $user->id));

            return self::FAILURE;
        }

        $profile = $user->syntheticProfile;
        if ($profile === null) {
            $this->error(sprintf('Synthetic user [%d] does not have a profile.', $user->id));

            return self::FAILURE;
        }

        if (! $profile->is_enabled) {
            $this->error(sprintf('Synthetic user [%d] profile is disabled.', $user->id));

            return self::FAILURE;
        }

        try {
            $validateSyntheticUserProfile->validate([
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
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! SyntheticDecisionProfiles::isAllowed((string) $profile->decision_profile)) {
            $this->error(
                'Invalid synthetic decision_profile. Allowed: ' . SyntheticDecisionProfiles::listForMessage() . '.',
            );

            return self::FAILURE;
        }

        $decisionProfile = (string) $profile->decision_profile;
        $sessionId = (string) Str::uuid();

        $this->line('Synthetic session started');
        $this->line('User: ' . $user->id);
        $this->line('Profile: ' . $decisionProfile);
        $this->line('Planned actions: ' . $actions);
        $this->line('Session id: ' . $sessionId);
        $this->newLine();

        try {
            $summary = $runSyntheticUserSessionAction->execute(
                user: $user,
                profile: $decisionProfile,
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
