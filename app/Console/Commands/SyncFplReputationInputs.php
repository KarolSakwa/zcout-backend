<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncFplReputationInputs extends Command
{
    protected $signature = 'zcout:sync-fpl-reputation-inputs {--player=}';
    protected $description = 'Sync FPL minutes (long-term), now_cost and selected_by_percent into player_reputation_stats';

    public function handle(): int
    {
        $res = Http::timeout(25)->get('https://fantasy.premierleague.com/api/bootstrap-static/');

        if (!$res->ok()) {
            $this->error("FPL request failed: status={$res->status()}");
            $this->line(substr($res->body(), 0, 800));
            return self::FAILURE;
        }

        $elements = $res->json('elements');
        if (!is_array($elements)) {
            $this->error('FPL response has no elements array');
            return self::FAILURE;
        }

        $byElementId = [];
        foreach ($elements as $e) {
            if (!is_array($e) || !isset($e['id'])) {
                continue;
            }

            $eid = (int) $e['id'];

            $selected = null;
            if (array_key_exists('selected_by_percent', $e)) {
                $s = str_replace(',', '.', (string) $e['selected_by_percent']);
                $selected = is_numeric($s) ? (float) $s : null;
            }

            $byElementId[$eid] = [
                'minutes' => (int) ($e['minutes'] ?? 0),
                'now_cost' => (int) ($e['now_cost'] ?? 0),
                'selected_by_percent' => $selected,
            ];
        }

        $q = DB::table('players')
            ->select(['id', 'fpl_element_id'])
            ->whereNotNull('fpl_element_id')
            ->orderBy('id');

        $playerOpt = $this->option('player');
        if ($playerOpt !== null) {
            $q->where('id', (int) $playerOpt);
        }

        $players = $q->get();
        if ($players->isEmpty()) {
            $this->info('No players with fpl_element_id');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $batch = [];
        $matched = 0;
        $missing = 0;

        foreach ($players as $p) {
            $playerId = (int) $p->id;
            $eid = (int) $p->fpl_element_id;

            if (!isset($byElementId[$eid])) {
                $missing++;
                continue;
            }

            $row = $byElementId[$eid];

            $batch[] = [
                'player_id' => $playerId,
                'minutes_long_term' => (int) $row['minutes'],
                'fpl_now_cost' => (int) $row['now_cost'],
                'fpl_selected_by_percent' => $row['selected_by_percent'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $matched++;
        }

        if ($batch) {
            DB::table('player_reputation_stats')->upsert(
                $batch,
                ['player_id'],
                ['minutes_long_term', 'fpl_now_cost', 'fpl_selected_by_percent', 'updated_at']
            );
        }

        $this->info("matched={$matched} missing={$missing}");

        return self::SUCCESS;
    }
}
