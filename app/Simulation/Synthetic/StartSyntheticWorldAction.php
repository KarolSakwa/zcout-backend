<?php

namespace App\Simulation\Synthetic;

use DomainException;

final class StartSyntheticWorldAction
{
    public function __construct(
        private readonly SyntheticWorldRuntime $runtime,
        private readonly SyntheticWorldScheduleMutex $mutex,
        private readonly TickSyntheticWorldAction $tickSyntheticWorldAction,
        private readonly GetSyntheticWorldStatusAction $getSyntheticWorldStatusAction,
    ) {
    }

    /**
     * @return array{
     *     environment_enabled: bool,
     *     runtime: \App\Models\SyntheticWorldRuntimeSettings,
     *     mutex: array{cleared: bool, reason: string}|null,
     *     tick: SyntheticWorldTickResult|null,
     *     status: SyntheticWorldStatus,
     *     warnings: list<string>
     * }
     */
    public function execute(bool $clearStaleMutex = false, bool $resetDailyPlan = false): array
    {
        $warnings = [];
        $environmentEnabled = $this->runtime->environmentEnabled();

        if (! $environmentEnabled) {
            throw new DomainException(
                'Environment automation is disabled. Set SYNTHETIC_WORLD_ENABLED=true in .env (or the process environment), clear config cache if used, and ensure `php artisan schedule:work` (or cron) is running.',
            );
        }

        $mutexResult = null;
        if ($clearStaleMutex) {
            $mutexResult = $this->mutex->clearIfStale();
            if (! $mutexResult['cleared'] && $mutexResult['reason'] === 'mutex_not_stale') {
                throw new DomainException(
                    'Schedule mutex exists but is not clearly stale (recent tick heartbeat). Inspect Redis cache locks / run status; do not use schedule:clear-cache as a routine fix.',
                );
            }
            if ($mutexResult['cleared']) {
                $warnings[] = 'Cleared stale Synthetic World schedule mutex.';
            }
        }

        if ($resetDailyPlan) {
            $configure = app(ConfigureSyntheticWorldAction::class)->execute([
                'all' => true,
                'reset_daily_plan' => true,
            ]);
            $warnings = array_merge($warnings, $configure->warnings);
        }

        $runtime = $this->runtime->markRunning('cli:start');

        $tick = $this->tickSyntheticWorldAction->execute();
        $status = $this->getSyntheticWorldStatusAction->execute();

        return [
            'environment_enabled' => true,
            'runtime' => $runtime,
            'mutex' => $mutexResult,
            'tick' => $tick,
            'status' => $status,
            'warnings' => $warnings,
        ];
    }
}
