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
use App\Simulation\Actions\InitializeSimulationStateFromTruthSnapshot;
use App\Simulation\Actions\CopySimulationRunTruthFromExistingRun;
use App\Simulation\Truth\BaselineJsonTruthProvider;

final class RunSimulationSmokeTest extends Command
{
    protected $signature = 'zcout:simulation-smoke {--mode=report} {--users=10} {--steps-per-user=1} {--seed=12345} {--reset=0} {--truth-run-id=} {--baseline-json=} {--label=}';

    protected $description = 'Run a basic simulation smoke test';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $userCount = max(1, (int) $this->option('users'));
        $stepsPerUser = max(1, (int) $this->option('steps-per-user'));
        $seed = (int) $this->option('seed');
        $truthRunId = $this->option('truth-run-id');
        $truthRunId = $truthRunId !== null && $truthRunId !== '' ? (int) $truthRunId : null;
        $baselineJsonPath = $this->option('baseline-json');
        $baselineJsonPath = is_string($baselineJsonPath) && trim($baselineJsonPath) !== ''
            ? trim($baselineJsonPath)
            : null;

        $label = $this->option('label');
        $label = is_string($label) && trim($label) !== ''
            ? trim($label)
            : null;

        $runRecord = SimulationRunModel::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'config' => [
                'users' => $userCount,
                'seed' => $seed,
            ],
            'started_at' => now(),
            'label' => $label,
        ]);

        $logPhase = function (string $message) use ($runRecord): void {
            $this->line("[run #{$runRecord->id}] {$message}");
        };

        if ($truthRunId !== null) {
            $logPhase("copying truth from run #{$truthRunId}");
            (new CopySimulationRunTruthFromExistingRun())->handle($truthRunId, $runRecord);
        } elseif ($baselineJsonPath !== null) {
            $logPhase("snapshotting truth from baseline json [{$baselineJsonPath}]");
            (new BaselineJsonTruthProvider($baselineJsonPath))->snapshotForRun($runRecord);
        } else {
            $logPhase('snapshotting truth');
            (new DatabaseSnapshotTruthProvider())->snapshotForRun($runRecord);
        }

        if ((string) $this->option('reset') === '1') {
            $logPhase('resetting live state');
            (new ResetSimulationState())->handle();

            $logPhase('initializing live state from truth snapshot');
            (new InitializeSimulationStateFromTruthSnapshot())->handle($runRecord->id);
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
                sources: [app(DuelInteractionSource::class)],
                output: $output,
            );

            $users = [];
            $appUserIds = DB::table('users')
                ->where('email', 'like', 'sim%@zcout.local')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($appUserIds === []) {
                throw new \RuntimeException('No simulation app users found (sim%@zcout.local).');
            }

            for ($i = 1; $i <= $userCount; $i++) {
                $bucket = $i % 10;

                $type = match (true) {
                    $bucket === 0 => 'expert',
                    $bucket === 1 || $bucket === 2 => 'noisy',
                    $bucket === 3 || $bucket === 4 => 'biased',
                    default => 'casual',
                };

                $isLogged = $i % 2 === 0;

                $users[] = new SimulatedUser(
                    id: 'u' . $i,
                    type: $type,
                    isLogged: $isLogged,
                    appUserId: $isLogged
                        ? $appUserIds[(int) (floor(($i - 1) / 2) % count($appUserIds))]
                        : null,
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

            $beforePlayerAttributeState = DB::table('player_attribute_ratings as par')
                ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
                ->select([
                    'par.player_id',
                    'a.key as attribute_key',
                    'par.rating',
                    'par.confidence',
                    'par.votes_count',
                ])
                ->get()
                ->mapWithKeys(fn ($row) => [
                    ((int) $row->player_id) . '|' . (string) $row->attribute_key => [
                        'rating' => (float) $row->rating,
                        'confidence' => (float) $row->confidence,
                        'votes_count' => (int) $row->votes_count,
                    ],
                ])
                ->all();

            $logPhase("running simulation for {$userCount} users x {$stepsPerUser} steps");
            $totalPlanned = $userCount * $stepsPerUser;

            $run->run($users, $context, function (int $processed) use ($logPhase, $totalPlanned): void {
                $percent = $totalPlanned > 0
                    ? round(($processed / $totalPlanned) * 100, 1)
                    : 0.0;

                $logPhase("progress {$processed}/{$totalPlanned} ({$percent}%)");
            });
            $logPhase('simulation finished, collecting metrics');

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

            $materializedAttributeCounts = SimulationRunEvent::query()
                ->where('simulation_run_id', $runRecord->id)
                ->where('source', 'duel')
                ->select('payload->attribute_key as attribute_key', DB::raw('COUNT(*) as count'))
                ->groupBy('attribute_key')
                ->pluck('count', 'attribute_key')
                ->map(fn ($count) => (int) $count)
                ->all();

            $pairEvents = SimulationRunEvent::query()
                ->where('simulation_run_id', $runRecord->id)
                ->where('source', 'duel')
                ->get();

            $materializedPairCounts = [];
            $materializedPairAttributeCounts = [];
            $topPairsReadable = [];

            foreach ($pairEvents as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];

                $playerAId = (int) ($payload['player_a_id'] ?? 0);
                $playerBId = (int) ($payload['player_b_id'] ?? 0);
                $playerAName = (string) ($payload['player_a_name'] ?? (string) $playerAId);
                $playerBName = (string) ($payload['player_b_name'] ?? (string) $playerBId);
                $attributeKey = (string) ($payload['attribute_key'] ?? 'unknown');

                if ($playerAId <= 0 || $playerBId <= 0) {
                    continue;
                }

                if ($playerAId <= $playerBId) {
                    $leftId = $playerAId;
                    $rightId = $playerBId;
                    $leftName = $playerAName;
                    $rightName = $playerBName;
                } else {
                    $leftId = $playerBId;
                    $rightId = $playerAId;
                    $leftName = $playerBName;
                    $rightName = $playerAName;
                }

                $pairKey = $leftId . 'vs' . $rightId;
                $pairAttributeKey = $pairKey . '|' . $attributeKey;
                $readableKey = $leftName . ' vs ' . $rightName . ' | ' . $attributeKey;

                $materializedPairCounts[$pairKey] = ($materializedPairCounts[$pairKey] ?? 0) + 1;
                $materializedPairAttributeCounts[$pairAttributeKey] = ($materializedPairAttributeCounts[$pairAttributeKey] ?? 0) + 1;
                $topPairsReadable[$readableKey] = ($topPairsReadable[$readableKey] ?? 0) + 1;
            }

            arsort($materializedPairCounts);
            arsort($materializedPairAttributeCounts);
            arsort($topPairsReadable);

            $topPairsReadable = array_slice($topPairsReadable, 0, 10, true);

            $touchedPlayerAttributeCounts = [];

            foreach ($pairEvents as $event) {
                $payload = is_array($event->payload) ? $event->payload : [];

                $playerAId = (int) ($payload['player_a_id'] ?? 0);
                $playerBId = (int) ($payload['player_b_id'] ?? 0);
                $playerAName = (string) ($payload['player_a_name'] ?? (string) $playerAId);
                $playerBName = (string) ($payload['player_b_name'] ?? (string) $playerBId);
                $attributeKey = (string) ($payload['attribute_key'] ?? 'unknown');
                $eventType = (string) ($event->event_type ?? '');

                if ($playerAId > 0) {
                    $keyA = $playerAId . '|' . $attributeKey;
                    $labelA = $playerAName . ' | ' . $attributeKey;

                    if (! isset($touchedPlayerAttributeCounts[$keyA])) {
                        $touchedPlayerAttributeCounts[$keyA] = [
                            'player_id' => $playerAId,
                            'player_name' => $playerAName,
                            'attribute_key' => $attributeKey,
                            'label' => $labelA,
                            'count' => 0,
                            'vote_touch_count' => 0,
                            'skip_touch_count' => 0,
                        ];
                    }

                    $touchedPlayerAttributeCounts[$keyA]['count']++;

                    if ($eventType === 'vote') {
                        $touchedPlayerAttributeCounts[$keyA]['vote_touch_count']++;
                    }

                    if ($eventType === 'skip') {
                        $touchedPlayerAttributeCounts[$keyA]['skip_touch_count']++;
                    }
                }

                if ($playerBId > 0) {
                    $keyB = $playerBId . '|' . $attributeKey;
                    $labelB = $playerBName . ' | ' . $attributeKey;

                    if (! isset($touchedPlayerAttributeCounts[$keyB])) {
                        $touchedPlayerAttributeCounts[$keyB] = [
                            'player_id' => $playerBId,
                            'player_name' => $playerBName,
                            'attribute_key' => $attributeKey,
                            'label' => $labelB,
                            'count' => 0,
                            'vote_touch_count' => 0,
                            'skip_touch_count' => 0,
                        ];
                    }

                    $touchedPlayerAttributeCounts[$keyB]['count']++;

                    if ($eventType === 'vote') {
                        $touchedPlayerAttributeCounts[$keyB]['vote_touch_count']++;
                    }

                    if ($eventType === 'skip') {
                        $touchedPlayerAttributeCounts[$keyB]['skip_touch_count']++;
                    }
                }
            }

            usort($touchedPlayerAttributeCounts, fn ($a, $b) => $b['count'] <=> $a['count']);

            $topTouchedPlayerAttributes = array_slice($touchedPlayerAttributeCounts, 0, 15);

            $topTouchedPlayerAttributes = array_map(function (array $item) use ($beforePlayerAttributeState): array {
                $row = DB::table('player_attribute_ratings as par')
                    ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
                    ->where('par.player_id', $item['player_id'])
                    ->where('a.key', $item['attribute_key'])
                    ->select([
                        'par.rating',
                        'par.confidence',
                        'par.votes_count',
                    ])
                    ->first();

                $stateKey = $item['player_id'] . '|' . $item['attribute_key'];
                $before = $beforePlayerAttributeState[$stateKey] ?? null;

                $currentRating = $row ? (float) $row->rating : null;
                $currentConfidence = $row ? (float) $row->confidence : null;
                $currentVotesCount = $row ? (int) $row->votes_count : null;

                $beforeRating = $before['rating'] ?? null;
                $beforeConfidence = $before['confidence'] ?? null;
                $beforeVotesCount = $before['votes_count'] ?? null;

                $item['before_rating'] = $beforeRating;
                $item['current_rating'] = $currentRating;
                $item['rating_delta'] = $currentRating !== null
                    ? $currentRating - (float) ($beforeRating ?? 0.0)
                    : null;

                $item['before_confidence'] = $beforeConfidence;
                $item['current_confidence'] = $currentConfidence;
                $item['confidence_delta'] = $currentConfidence !== null
                    ? $currentConfidence - (float) ($beforeConfidence ?? 0.0)
                    : null;

                $item['before_votes_count'] = $beforeVotesCount;
                $item['current_votes_count'] = $currentVotesCount;
                $item['votes_count_delta'] = $currentVotesCount !== null
                    ? $currentVotesCount - (int) ($beforeVotesCount ?? 0)
                    : null;

                return $item;
            }, $topTouchedPlayerAttributes);

            $topAttributeDeltasAfterRun = DB::table('simulation_run_truth_ratings as truth')
                ->join('attributes as a', 'a.key', '=', 'truth.attribute_key')
                ->join('player_attribute_ratings as par', function ($join) {
                    $join->on('par.player_id', '=', 'truth.player_id')
                        ->on('par.attribute_id', '=', 'a.id');
                })
                ->join('players as p', 'p.id', '=', 'truth.player_id')
                ->where('truth.simulation_run_id', $runRecord->id)
                ->select([
                    'truth.player_id',
                    'p.name as player_name',
                    'truth.attribute_key',
                    'truth.truth_rating as before_rating',
                    'par.rating as current_rating',
                    DB::raw('(par.rating - truth.truth_rating) as signed_delta'),
                    DB::raw('ABS(par.rating - truth.truth_rating) as abs_delta'),
                    'par.confidence',
                    'par.votes_count',
                ])
                ->orderByDesc('abs_delta')
                ->limit(50)
                ->get()
                ->map(fn ($row) => [
                    'player_id' => (int) $row->player_id,
                    'player_name' => (string) $row->player_name,
                    'attribute_key' => (string) $row->attribute_key,
                    'before_rating' => (float) $row->before_rating,
                    'current_rating' => (float) $row->current_rating,
                    'signed_delta' => (float) $row->signed_delta,
                    'abs_delta' => (float) $row->abs_delta,
                    'confidence' => (float) $row->confidence,
                    'votes_count' => (int) $row->votes_count,
                ])
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
                    'materialized_attribute_counts' => $materializedAttributeCounts,
                    'materialized_pair_counts' => $materializedPairCounts,
                    'materialized_pair_attribute_counts' => $materializedPairAttributeCounts,
                    'top_pairs_readable' => $topPairsReadable,
                    'top_touched_player_attributes' => $topTouchedPlayerAttributes,
                    'top_attribute_deltas_after_run' => $topAttributeDeltasAfterRun,
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
                $metrics = $result['metrics'];
                $delta = $metrics['delta'];
                $topPairs = $metrics['top_pairs_readable'] ?? [];

                $planned = (int) ($metrics['planned_interactions'] ?? 0);
                $votes = (int) ($metrics['materialized_event_counts']['vote'] ?? 0);
                $skips = (int) ($metrics['materialized_event_counts']['skip'] ?? 0);
                $deltaVotes = (int) ($delta['votes'] ?? 0);
                $deltaDuels = (int) ($delta['duels'] ?? 0);
                $deltaRatings = (int) ($delta['ratings'] ?? 0);

                $this->info(
                    "Materialize mode executed for run #{$runRecord->id}. "
                    . "planned={$planned} "
                    . "votes={$votes} "
                    . "skips={$skips} "
                    . "Δvotes={$deltaVotes} Δduels={$deltaDuels} Δratings={$deltaRatings}"
                );

                if ($topPairs !== []) {
                    $this->line('Top pairs:');

                    foreach ($topPairs as $label => $count) {
                        $this->line("- {$label}: {$count}");
                    }
                }
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
