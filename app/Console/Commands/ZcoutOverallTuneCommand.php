<?php

namespace App\Console\Commands;

use App\Support\OverallConfig;
use App\Support\RadarAxesBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ZcoutOverallTuneCommand extends Command
{
    protected $signature = 'zcout:overall-tune';

    protected $description = 'Tune overall weights';
    protected int $playersNum = 200;

    public function handle(): int
    {
        $config = config('overall');
        $currentWeights = $config['archetype_axis_weights'];

        $players = $this->loadPlayers();

        $beforeRows = $this->buildRows($players, $currentWeights);

        $this->showGlobalTop('Global TOP ' . $this->playersNum . ' before', $beforeRows);

        $outfieldCurrentSum = $this->avgOutfieldSum($currentWeights);
        $gkCurrentSum = array_sum($currentWeights['GK']);

        $outfieldSum = (float) $this->ask('Outfield total sum', (string) round($outfieldCurrentSum, 4));
        $gkSum = (float) $this->ask('GK total sum', (string) round($gkCurrentSum, 4));

        $newWeights = $currentWeights;

        foreach ($newWeights as $archetype => $weights) {
            $targetSum = $archetype === 'GK' ? $gkSum : $outfieldSum;
            $newWeights[$archetype] = $this->scaleToSum($weights, $targetSum);
        }

        $afterRows = $this->buildRows($players, $newWeights);

        $this->showGlobalComparison('Global TOP ' . $this->playersNum . ' before/after', $beforeRows, $afterRows);

        if ($this->confirm('Tune specific archetype distribution?', false)) {
            $archetype = $this->choice('Choose archetype', array_keys($newWeights));

            $newWeights[$archetype] = $this->tuneArchetypeDistribution($archetype, $newWeights[$archetype]);

            $afterRows = $this->buildRows($players, $newWeights);

            $this->showGlobalComparison('Final Global TOP ' . $this->playersNum . ' before/after', $beforeRows, $afterRows);
        }

        if ($this->confirm('Write changes to config/overall.php?', false)) {
            $config['archetype_axis_weights'] = $newWeights;
            $this->writeConfig($config);
            $this->info('config/overall.php updated');
        }

        return self::SUCCESS;
    }

    private function loadPlayers()
    {
        return DB::table('players as p')
            ->join('positions as pos', 'pos.id', '=', 'p.position_id')
            ->select('p.id', 'p.name', 'pos.key as position')
            ->orderBy('p.id')
            ->get()
            ->map(function ($player) {
                $attrs = DB::table('player_attribute_ratings as par')
                    ->join('attributes as a', 'a.id', '=', 'par.attribute_id')
                    ->where('par.player_id', $player->id)
                    ->select('a.key', 'par.rating')
                    ->get()
                    ->map(fn ($row) => [
                        'key' => (string) $row->key,
                        'rating' => (float) $row->rating,
                    ])
                    ->all();

                return [
                    'id' => (int) $player->id,
                    'name' => (string) $player->name,
                    'position' => (string) $player->position,
                    'attrs' => $attrs,
                ];
            });
    }

    private function buildRows($players, array $weights)
    {
        return $players
            ->map(function (array $player) use ($weights) {
                $pos = $player['position'];
                $archetype = OverallConfig::archetypeForPosition($pos);

                if (!$archetype || !isset($weights[$archetype])) {
                    return null;
                }

                $radarAxes = RadarAxesBuilder::build($pos, $player['attrs']);
                $overall = $this->overallFromRadarAxes($radarAxes, $weights[$archetype]);

                return [
                    'id' => $player['id'],
                    'name' => $player['name'],
                    'position' => $pos,
                    'archetype' => $archetype,
                    'overall' => $overall,
                ];
            })
            ->filter()
            ->sortByDesc('overall')
            ->values();
    }

    private function overallFromRadarAxes(array $radarAxes, array $weights): float
    {
        $valuesByAxis = collect($radarAxes)->mapWithKeys(
            fn (array $axis) => [(string) ($axis['key'] ?? '') => (float) ($axis['value'] ?? 0)]
        );

        $weightedSum = 0.0;

        foreach ($weights as $axisKey => $weight) {
            if (!$valuesByAxis->has($axisKey)) {
                continue;
            }

            $weightedSum += ((float) $valuesByAxis[$axisKey]) * (float) $weight;
        }

        return round($weightedSum, 2);
    }

    private function showGlobalTop(string $title, $rows): void
    {
        $this->newLine();
        $this->info($title);
        $this->newLine();

        $this->table(
            ['#', 'Player', 'Pos', 'Archetype', 'Overall'],
            $rows->take($this->playersNum)->values()->map(fn ($row, $i) => [
                $i + 1,
                $row['name'],
                $row['position'],
                $row['archetype'],
                $row['overall'],
            ])->all()
        );
    }

    private function showGlobalComparison(string $title, $beforeRows, $afterRows): void
    {
        $beforeById = $beforeRows->keyBy('id');

        $this->newLine();
        $this->info($title);
        $this->newLine();

        $this->table(
            ['#', 'Player', 'Pos', 'Archetype', 'Before', 'After', 'Delta'],
            $afterRows->take($this->playersNum)->values()->map(function ($row, $i) use ($beforeById) {
                $before = $beforeById[$row['id']]['overall'] ?? null;
                $after = $row['overall'];

                return [
                    $i + 1,
                    $row['name'],
                    $row['position'],
                    $row['archetype'],
                    $before,
                    $after,
                    $before === null ? null : round($after - $before, 2),
                ];
            })->all()
        );
    }

    private function avgOutfieldSum(array $weights): float
    {
        $sums = collect($weights)
            ->reject(fn ($_, $archetype) => $archetype === 'GK')
            ->map(fn ($axisWeights) => array_sum($axisWeights));

        return (float) $sums->avg();
    }

    private function scaleToSum(array $weights, float $targetSum): array
    {
        $currentSum = array_sum($weights);

        if ($currentSum <= 0) {
            return $weights;
        }

        return collect($weights)
            ->map(fn ($weight) => round(((float) $weight / $currentSum) * $targetSum, 6))
            ->all();
    }

    private function tuneArchetypeDistribution(string $archetype, array $weights): array
    {
        $sum = array_sum($weights);

        $this->newLine();
        $this->info($archetype . ' distribution. Total sum: ' . round($sum, 4));
        $this->newLine();

        $percentages = [];

        foreach ($weights as $axis => $weight) {
            $currentPercent = $sum > 0 ? round(((float) $weight / $sum) * 100, 2) : 0.0;
            $input = $this->ask($axis . ' [%]', (string) $currentPercent);
            $percentages[$axis] = (float) $input;
        }

        $percentSum = array_sum($percentages);

        if ($percentSum <= 0) {
            return $weights;
        }

        return collect($percentages)
            ->map(fn ($percent) => round(($percent / $percentSum) * $sum, 6))
            ->all();
    }

    private function writeConfig(array $config): void
    {
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        file_put_contents(config_path('overall.php'), $content);
    }
}
