<?php

namespace App\Simulation;

use App\Simulation\Contracts\InteractionSource;
use App\Simulation\Contracts\SimulationOutput;
use App\Simulation\Data\SimulatedUser;

final class SimulationRun
{
    /**
     * @param  array<InteractionSource>  $sources
     */
    public function __construct(
        private readonly array $sources,
        private readonly SimulationOutput $output,
    ) {
    }

    /**
     * @param  array<SimulatedUser>  $users
     */
    public function run(array $users, SimulationContext $context): void
    {
        $stepsPerUser = max(1, (int) ($context->config['steps_per_user'] ?? 1));

        foreach ($users as $user) {
            for ($step = 0; $step < $stepsPerUser; $step++) {
                foreach ($this->sources as $source) {
                    if (! $source->canGenerateFor($user, $context)) {
                        continue;
                    }

                    $opportunity = $source->generateOpportunity($user, $context);

                    if ($opportunity === null) {
                        continue;
                    }

                    $decision = $source->simulateDecision($opportunity, $user, $context);

                    if ($decision === null) {
                        continue;
                    }

                    $this->output->handleDecision($user, $opportunity, $decision, $context);
                }
            }
        }
    }
}
