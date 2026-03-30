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
        $seed = (int) ($context->config['seed'] ?? 12345);

        for ($step = 0; $step < $stepsPerUser; $step++) {
            $stepUsers = $users;

            mt_srand($seed + $step);
            shuffle($stepUsers);

            $stepContext = new SimulationContext(
                mode: $context->mode,
                runId: $context->runId,
                now: $context->now,
                config: $context->config,
                currentStep: $step + 1,
            );

            foreach ($stepUsers as $user) {
                foreach ($this->sources as $source) {
                    if (! $source->canGenerateFor($user, $stepContext)) {
                        continue;
                    }

                    $opportunity = $source->generateOpportunity($user, $stepContext);

                    if ($opportunity === null) {
                        continue;
                    }

                    $decision = $source->simulateDecision($opportunity, $user, $stepContext);

                    if ($decision === null) {
                        continue;
                    }

                    $this->output->handleDecision($user, $opportunity, $decision, $stepContext);
                }
            }
        }
    }
}
