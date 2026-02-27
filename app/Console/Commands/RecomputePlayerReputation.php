<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecomputePlayerReputation extends Command
{
    protected $signature = 'zcout:recompute-player-reputation {--x=180} {--y=0.25}';
    protected $description = 'Recompute player reputation (0-1) and long-tail flag based on minutes windows and FPL cost';

    public function handle(): int
    {
        $x = (int) $this->option('x');
        $y = (float) $this->option('y');

        $max90 = (int) DB::table('player_reputation_stats')->max('minutes_90d');
        $maxLT = (int) DB::table('player_reputation_stats')->max('minutes_long_term');
        $maxCost = (int) DB::table('player_reputation_stats')->max('fpl_now_cost');

        $now = Carbon::now();
        $updated = 0;

        DB::table('player_reputation_stats')
            ->select(['player_id', 'minutes_90d', 'minutes_long_term', 'fpl_now_cost'])
            ->orderBy('player_id')
            ->chunk(200, function ($rows) use ($max90, $maxLT, $maxCost, $x, $y, $now, &$updated) {
                $batch = [];

                foreach ($rows as $r) {
                    $baselineMinutes = $this->norm((int) $r->minutes_long_term, $maxLT);
                    $baselineCost = $this->norm((int) $r->fpl_now_cost, $maxCost);

                    $baseline = (0.4 * $baselineMinutes) + (0.6 * $baselineCost);

                    $recency = $this->norm((int) $r->minutes_90d, $max90);

                    $rep = (0.7 * $baseline) + (0.3 * $recency);
                    if ($rep < 0) $rep = 0;
                    if ($rep > 1) $rep = 1;

                    $isLongTail = ((int) $r->minutes_90d < $x) && ($rep < $y);

                    $batch[] = [
                        'player_id' => (int) $r->player_id,
                        'player_rep' => $rep,
                        'is_long_tail' => $isLongTail,
                        'computed_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($batch) {
                    DB::table('player_reputation_stats')->upsert(
                        $batch,
                        ['player_id'],
                        ['player_rep', 'is_long_tail', 'computed_at', 'updated_at']
                    );
                    $updated += count($batch);
                }
            });

        $this->info("max90={$max90} maxLT={$maxLT} maxCost={$maxCost} x={$x} y={$y} updated={$updated}");

        return self::SUCCESS;
    }

    private function norm(int $x, int $max): float
    {
        if ($x <= 0 || $max <= 0) {
            return 0.0;
        }

        $v = $x / $max;
        if ($v < 0) $v = 0;
        if ($v > 1) $v = 1;

        return sqrt($v);
    }
}
