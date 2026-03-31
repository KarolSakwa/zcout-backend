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
use Illuminate\Support\Facades\DB;
use App\Simulation\Truth\DatabaseSnapshotTruthProvider;
use App\Simulation\Actions\ResetSimulationState;
use App\Models\SimulationRunEvent;

final class RunSimulationSmokeTest extends Command
{
    protected $signature = 'zcout:simulation-smoke {--mode=report} {--users=10} {--steps-per-user=1} {--seed=12345} {--reset=0}';

    protected $description = 'Run a basic simulation smoke test';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $userCount = max(1, (int) $this->option('users'));
        $stepsPerUser = max(1, (int) $this->option('steps-per-user'));
        $seed = (int) $this->option('seed');

        $runRecord = SimulationRunModel::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'config' => [
                'users' => $userCount,
                'seed' => $seed,
            ],
            'started_at' => now(),
        ]);

        (new DatabaseSnapshotTruthProvider())->snapshotForRun($runRecord);

        if ((string) $this->option('reset') === '1') {
            (new ResetSimulationState())->handle();
        }

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

            $users = [];

            for ($i = 1; $i <= $userCount; $i++) {
                $users[] = new SimulatedUser(
                    id: 'u' . $i,
                    type: $i % 5 === 0 ? 'expert' : 'casual',
                    isLogged: $i % 2 === 0,
                );
            }

            $context = new SimulationContext(
                mode: $mode,
                runId: $runRecord->id,
                now: new \DateTimeImmutable(),
                config: [
                    'users' => $userCount,
                    'steps_per_user' => $stepsPerUser,
                    'seed' => $seed,
                ],
            );

            $beforeMetrics = [
                'votes' => (int) DB::table('votes')->count(),
                'duels' => (int) DB::table('duels')->count(),
                'ratings' => (int) DB::table('player_attribute_ratings')->count(),
            ];

            $run->run($users, $context);

            $afterMetrics = [
                'votes' => (int) DB::table('votes')->count(),
                'duels' => (int) DB::table('duels')->count(),
                'ratings' => (int) DB::table('player_attribute_ratings')->count(),
            ];

            $plannedInteractions = $userCount * $stepsPerUser;

            $skipCount = $output instanceof CollectingSimulationOutput
                ? (int) (($output->summary()['decision_counts']['skip'] ?? 0))
                : (int) SimulationRunEvent::query()
                    ->where('simulation_run_id', $runRecord->id)
                    ->where('event_type', 'skip')
                    ->count();

            $materializedEventCounts = SimulationRunEvent::query()
                ->where('simulation_run_id', $runRecord->id)
                ->select('event_type', DB::raw('COUNT(*) as count'))
                ->groupBy('event_type')
                ->pluck('count', 'event_type')
                ->map(fn ($count) => (int) $count)
                ->all();

            $result = [
                'metrics' => [
                    'before' => $beforeMetrics,
                    'after' => $afterMetrics,
                    'delta' => [
                        'votes' => $afterMetrics['votes'] - $beforeMetrics['votes'],
                        'duels' => $afterMetrics['duels'] - $beforeMetrics['duels'],
                        'ratings' => $afterMetrics['ratings'] - $beforeMetrics['ratings'],
                    ],
                    'planned_interactions' => $plannedInteractions,
                    'skips' => $skipCount,
                    'materialized_event_counts' => $materializedEventCounts,
                ],
            ];
            $isReportOutput = $output instanceof CollectingSimulationOutput;

            if ($isReportOutput) {
                $result['report'] = [
                    'summary' => $output->summary(),
                    'items' => $output->items(),
                ];
            }

            $runRecord->update([
                'status' => 'finished',
                'finished_at' => now(),
                'result' => $result,
            ]);

            if ($isReportOutput) {
                $this->line(json_encode($output->summary(), JSON_PRETTY_PRINT));
            } else {
                $delta = $result['metrics']['delta'];

                $this->info(
                    "Materialize mode executed for run #{$runRecord->id}. "
                    . "Δvotes={$delta['votes']} Δduels={$delta['duels']} Δratings={$delta['ratings']}"
                );
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
