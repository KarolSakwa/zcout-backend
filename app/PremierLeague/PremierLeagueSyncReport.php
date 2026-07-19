<?php

namespace App\PremierLeague;

final class PremierLeagueSyncReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<array<string, mixed>>  $clubLines
     * @param  list<array<string, mixed>>  $playerLines
     * @param  list<array<string, mixed>>  $lockLines
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $verify
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $dryRun,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $clubLines = [],
        public readonly array $playerLines = [],
        public readonly array $lockLines = [],
        public readonly array $counts = [],
        public readonly array $verify = [],
        public readonly bool $applied = false,
    ) {
    }

    public function hasBlockingErrors(): bool
    {
        return $this->errors !== [];
    }
}
