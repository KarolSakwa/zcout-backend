<?php

namespace App\Simulation\Synthetic;

final class ConfigureSyntheticWorldResult
{
    /**
     * @param  array<string, int|float>  $changes
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $selector,
        public readonly int $profileCount,
        public readonly int $updatedCount,
        public readonly bool $dryRun,
        public readonly bool $resetDailyPlan,
        public readonly int $cancelledSessions,
        public readonly array $changes,
        public readonly array $before,
        public readonly array $after,
        public readonly array $warnings,
        public readonly int $activeSessions,
    ) {
    }
}
