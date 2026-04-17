<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SimulateAttribute extends Command
{
    protected $signature = 'sim:attr';
    protected $description = 'Simulate attribute rating changes';

    public function handle()
    {
        $this->info('Simulation started');

        $baselineRating = 90.0;
        $eventTypeFactor = 1.0;
        $profiles = [
            'anon' => 0.5,
            'user_default' => 1.0,
            'scout_founder' => 2.0,
        ];

        $floor = 0.04;
        $m = 0.08;

        $this->line("Baseline rating: {$baselineRating}");
        $this->line("Event type factor: {$eventTypeFactor}");
        $this->line("Confidence formula: factor = {$floor} + {$m} * (1 - conf/100)");
        $this->line('Outlier formula: factor = 1 / (1 + diff / s)');
        $this->line(str_repeat('-', 72));

        $scenarios = [
            ['label' => 'Normal deviation', 'vote' => 84.0],
            ['label' => 'Strong outlier', 'vote' => 70.0],
        ];

        $confLevels = [5, 50, 80];
        $sValues = [8.0, 12.0, 20.0];

        foreach ($scenarios as $scenario) {
            $vote = $scenario['vote'];
            $signalDelta = $vote - $baselineRating;
            $diff = abs($signalDelta);

            $this->info($scenario['label'] . " | vote = {$vote} | diff = {$diff}");
            $this->line(str_repeat('=', 72));

            foreach ($sValues as $s) {
                $outlierFactor = 1 / (1 + ($diff / $s));
                $outlierFactor = round($outlierFactor, 4);

                $this->line("s = {$s} | outlier factor = {$outlierFactor}");

                foreach ($profiles as $profileName => $profileFactor) {
                    $this->line("Profile: {$profileName} (factor {$profileFactor})");

                    foreach ($confLevels as $conf) {
                        $confidenceFactor = $floor + $m * (1 - ($conf / 100));
                        $confidenceFactor = round($confidenceFactor, 4);

                        $change = $signalDelta * $confidenceFactor * $eventTypeFactor * $profileFactor * $outlierFactor;
                        $change = round($change, 3);

                        $final = round($baselineRating + $change, 3);

                        $this->line("conf {$conf}% → conf factor {$confidenceFactor} → change {$change} → final {$final}");
                    }

                    $this->line(str_repeat('-', 48));
                }

                $this->line(str_repeat('*', 72));
            }

            $this->line('');
        }

        $this->info('Simulation finished');

        return self::SUCCESS;
    }
}
