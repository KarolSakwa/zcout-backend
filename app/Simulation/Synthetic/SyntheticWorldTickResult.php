<?php

namespace App\Simulation\Synthetic;

final class SyntheticWorldTickResult
{
    public function __construct(
        public int $usersConsidered = 0,
        public int $inactiveUsersToday = 0,
        public int $sessionsStarted = 0,
        public int $sessionStartConflicts = 0,
        public int $dueSessionsFound = 0,
        public int $sessionsAdvanced = 0,
        public int $votes = 0,
        public int $skips = 0,
        public int $actionFailures = 0,
        public int $completedSessions = 0,
        public int $failedSessions = 0,
        public int $errors = 0,
    ) {
    }
}
