<?php

namespace App\Console\Commands;

use App\Simulation\Data\SimulatedUser;
use App\Simulation\Outputs\CollectingSimulationOutput;
use App\Simulation\Outputs\MaterializingSimulationOutput;
use App\Simulation\SimulationContext;
use App\Simulation\SimulationRun;
use App\Simulation\Sources\DuelInteractionSource;
use Illuminate\Console\Command;
use App\Simulation\Processors\NullSimulationDecisionProcessor;

final class RunSimulationSmokeTest extends Command
{
    protected $signature = 'zcout:simulation-smoke {--mode=report}';

    protected $description = 'Run a basic simulation smoke test';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');

        $output = $mode === 'materialize'
            ? new MaterializingSimulationOutput(new NullSimulationDecisionProcessor())
            : new CollectingSimulationOutput();

        $run = new SimulationRun(
            sources: [new DuelInteractionSource()],
            output: $output,
        );

        $users = [
            new SimulatedUser(id: 'u1', type: 'casual', isLogged: false),
            new SimulatedUser(id: 'u2', type: 'expert', isLogged: true),
        ];

        $context = new SimulationContext(
            mode: $mode,
            runId: 1,
            now: new \DateTimeImmutable(),
            config: [],
        );

        $run->run($users, $context);

        if ($output instanceof CollectingSimulationOutput) {
            $this->line(json_encode($output->items(), JSON_PRETTY_PRINT));
        } else {
            $this->info('Materialize mode executed.');
        }

        return self::SUCCESS;
    }
}
