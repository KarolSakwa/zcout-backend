<?php

namespace App\Console\Commands;

use App\Simulation\Synthetic\ConfigureSyntheticWorldAction;
use App\Simulation\Synthetic\ConfigureSyntheticWorldResult;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class ConfigureSyntheticWorldCommand extends Command
{
    protected $signature = 'zcout:synthetic-world:configure
        {--sessions-min= : sessions_per_day_min}
        {--sessions-max= : sessions_per_day_max}
        {--actions-min= : actions_per_session_min}
        {--actions-max= : actions_per_session_max}
        {--delay-min= : delay_seconds_min}
        {--delay-max= : delay_seconds_max}
        {--skip-probability= : skip_probability 0..1}
        {--decision-accuracy= : decision_accuracy 0..1}
        {--noise-level= : noise_level 0..1}
        {--pool= : Managed pool key}
        {--archetype= : expert|casual|noisy}
        {--all : All enabled profiles}
        {--dry-run : Show plan without writing}
        {--reset-daily-plan : Cancel active world sessions so new limits can apply today}';

    protected $description = 'Configure Synthetic World profile intensity parameters';

    public function handle(ConfigureSyntheticWorldAction $action): int
    {
        try {
            $result = $action->execute([
                'sessions_min' => $this->optionOrNull('sessions-min'),
                'sessions_max' => $this->optionOrNull('sessions-max'),
                'actions_min' => $this->optionOrNull('actions-min'),
                'actions_max' => $this->optionOrNull('actions-max'),
                'delay_min' => $this->optionOrNull('delay-min'),
                'delay_max' => $this->optionOrNull('delay-max'),
                'skip_probability' => $this->optionOrNull('skip-probability'),
                'decision_accuracy' => $this->optionOrNull('decision-accuracy'),
                'noise_level' => $this->optionOrNull('noise-level'),
                'pool' => $this->optionOrNull('pool'),
                'archetype' => $this->optionOrNull('archetype'),
                'all' => (bool) $this->option('all'),
                'dry_run' => (bool) $this->option('dry-run'),
                'reset_daily_plan' => (bool) $this->option('reset-daily-plan'),
            ]);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result);

        return self::SUCCESS;
    }

    private function optionOrNull(string $name): mixed
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : $value;
    }

    private function renderResult(ConfigureSyntheticWorldResult $result): void
    {
        $this->line($result->dryRun ? 'Synthetic World configure (dry-run)' : 'Synthetic World configure');
        $this->line('Selector: '.$result->selector);
        $this->line('Profiles: '.$result->profileCount);
        $this->line('Updated: '.$result->updatedCount);
        $this->line('Active sessions now: '.$result->activeSessions);
        $this->line('Reset daily plan: '.($result->resetDailyPlan ? 'yes' : 'no'));
        $this->line('Cancelled sessions: '.$result->cancelledSessions);
        $this->newLine();

        $rows = [];
        foreach ($result->changes as $field => $newValue) {
            $oldValues = collect($result->before)->pluck($field)->unique()->values()->all();
            $newValues = collect($result->after)->pluck($field)->unique()->values()->all();
            $rows[] = [
                $field,
                implode(',', array_map('strval', $oldValues)),
                implode(',', array_map('strval', $newValues)),
            ];
        }

        if ($rows !== []) {
            $this->table(['Field', 'Old (unique)', 'New (unique)'], $rows);
        } else {
            $this->line('No field changes.');
        }

        if ($result->warnings !== []) {
            $this->newLine();
            $this->line('Warnings');
            foreach ($result->warnings as $warning) {
                $this->line('  - '.$warning);
            }
        }
    }
}
