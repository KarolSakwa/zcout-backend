<?php

namespace App\Simulation\Synthetic;

use App\Models\SyntheticUserSession;

final class AdvanceSyntheticUserSessionResult
{
    public function __construct(
        public readonly SyntheticSessionActionResult $action,
        public readonly SyntheticUserSession $session,
    ) {
    }
}
