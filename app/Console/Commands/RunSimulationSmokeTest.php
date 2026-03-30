<?php

namespace App\Console\Commands;

use App\Models\SimulationRun as SimulationRunModel;
use App\Simulation\Data\SimulatedUser;
use App\Simulation\Outputs\CollectingSimulationOutput;
use App\Simulation\Outputs\MaterializingSimulationOutput;
use App\Simulation\Processors\DuelSimulationDecisionProcessor;
use App\Simulation\Processors\RoutingSimulationDecisionProcessor;
use App\Simulation\SimulationContext;
use App\Simulation\SimulationRun;
use App\Simulation\Sources\DuelInteractionSource;
use Illuminate\Console\Command;

final class RunSimulationSmokeTest extends Command
{
    protected $signature = 'zcout:simulation-smoke {--mode=report}';

    protected $description = 'Run a basic simulation smoke test';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');

        $runRecord = SimulationRunModel::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'config' => [],
            'started_at' => now(),
        ]);

        try {
            $output = $mode === 'materialize'
                ? new MaterializingSimulationOutput(
                    new RoutingSimulationDecisionProcessor([
                        'duel' => new DuelSimulationDecisionProcessor(),
                    ])
                )
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
                runId: $runRecord->id,
                now: new \DateTimeImmutable(),
                config: [],
            );

            $run->run($users, $context);

            $runRecord->update([
                'status' => 'finished',
                'finished_at' => now(),
                'result' => $output instanceof CollectingSimulationOutput
                    ? ['items' => $output->items()]
                    : null,
            ]);

            if ($output instanceof CollectingSimulationOutput) {
                $this->line(json_encode($output->items(), JSON_PRETTY_PRINT));
            } else {
                $this->info("Materialize mode executed for run #{$runRecord->id}.");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $runRecord->update([
                'status' => 'failed',
                'finished_at' => now(),
                'result' => [
                    'error' => $e->getMessage(),
                ],
            ]);

            throw $e;
        }
    }
}
