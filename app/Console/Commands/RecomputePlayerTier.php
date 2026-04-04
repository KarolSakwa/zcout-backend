<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecomputePlayerTier extends Command
{
    protected $signature = 'zcout:recompute-player-tier';
    protected $description = 'Recompute player tiers (A/B/C) from player_rep ranking';

    public function handle(): int
    {
        $rows = DB::table('player_reputation_stats')
            ->select(['player_id', 'player_rep'])
            ->orderByRaw('player_rep DESC NULLS LAST, player_id ASC')
            ->get();

        $total = $rows->count();

        if ($total === 0) {
            $this->info('No player_reputation_stats rows found.');
            return self::SUCCESS;
        }

        $aMax = (int) ceil($total * 0.15);
        $bMax = (int) ceil($total * 0.45);

        $now = Carbon::now();
        $batch = [];
        $updated = 0;

        foreach ($rows as $index => $row) {
            $rank = $index + 1;

            $tier = 'C';
            if ($rank <= $aMax) {
                $tier = 'A';
            } elseif ($rank <= $bMax) {
                $tier = 'B';
            }

            $batch[] = [
                'player_id' => (int) $row->player_id,
                'tier' => $tier,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('player_reputation_stats')->upsert(
                    $batch,
                    ['player_id'],
                    ['tier', 'updated_at']
                );

                $updated += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('player_reputation_stats')->upsert(
                $batch,
                ['player_id'],
                ['tier', 'updated_at']
            );

            $updated += count($batch);
        }

        $countA = DB::table('player_reputation_stats')->where('tier', 'A')->count();
        $countB = DB::table('player_reputation_stats')->where('tier', 'B')->count();
        $countC = DB::table('player_reputation_stats')->where('tier', 'C')->count();

        $this->info("total={$total} updated={$updated} A={$countA} B={$countB} C={$countC}");

        return self::SUCCESS;
    }
}
