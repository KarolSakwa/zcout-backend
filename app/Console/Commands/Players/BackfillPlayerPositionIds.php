<?php

namespace App\Console\Commands\Players;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPlayerPositionIds extends Command
{
    protected $signature = 'zcout:backfill-player-positions {--dry-run : Do not write updates}';
    protected $description = 'Backfill players.position_id from legacy players.position string';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Mapowanie z football-data (i podobnych) -> nasze positions.key
        $labelToKey = [
            'Goalkeeper' => 'GK',

            'Right-Back' => 'RB',
            'Left-Back' => 'LB',
            'Centre-Back' => 'CB',

            'Defensive Midfield' => 'DM',
            'Central Midfield' => 'CM',
            'Attacking Midfield' => 'AM',

            'Right Winger' => 'RW',
            'Left Winger' => 'LW',
            'Centre-Forward' => 'ST',

            // “brak danych” / ogólne
            'Defence' => 'DEF',
            'Midfield' => 'MID',
            'Offence' => 'ATT',
        ];

        $posIdByKey = DB::table('positions')->pluck('id', 'key');
        $posIdByLabel = DB::table('positions')->pluck('id', 'label');

        $updated = 0;
        $unknown = 0;
        $already = 0;

        DB::table('players')
            ->select('id', 'position', 'position_id')
            ->whereNotNull('position')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (
                $dryRun,
                $labelToKey,
                $posIdByKey,
                $posIdByLabel,
                &$updated,
                &$unknown,
                &$already
            ) {
                foreach ($rows as $p) {
                    if (!is_null($p->position_id)) {
                        $already++;
                        continue;
                    }

                    $raw = trim((string) $p->position);
                    if ($raw === '') {
                        continue;
                    }

                    // Normalizacja (na wszelki wypadek)
                    $raw = str_replace(["\u{2013}", "\u{2014}"], '-', $raw);

                    $posId = null;

                    if (isset($labelToKey[$raw])) {
                        $key = $labelToKey[$raw];
                        $posId = $posIdByKey[$key] ?? null;
                    } else {
                        // jeśli trafisz w przyszłości w dokładny label w tabeli positions
                        $posId = $posIdByLabel[$raw] ?? null;
                    }

                    if (!$posId) {
                        $unknown++;
                        $this->warn("Unknown position '{$raw}' for player_id={$p->id}");
                        continue;
                    }

                    DB::table('players')->where('id', $p->id)->update([
                        'position_id' => $posId,
                    ]);

                    $updated++;
                }
            });

        $this->info("done | updated={$updated} unknown={$unknown} alreadyHadPositionId={$already} dryRun=" . ($dryRun ? '1' : '0'));

        return self::SUCCESS;
    }
}
