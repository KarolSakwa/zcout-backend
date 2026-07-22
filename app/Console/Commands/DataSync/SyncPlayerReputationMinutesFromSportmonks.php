<?php

namespace App\Console\Commands\DataSync;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncPlayerReputationMinutesFromSportmonks extends Command
{
    protected $signature = 'zcout:sync-reputation-minutes-sm {--player=} {--limit=50} {--delta=0.7}';
    protected $description = 'Sync long-term minutes from Sportmonks into player_reputation_stats using last 2 seasons from player statistics';

    public function handle(): int
    {
        $token = env('SPORTMONKS_TOKEN');
        if (!$token) {
            $this->error('Missing SPORTMONKS_TOKEN in .env');
            return self::FAILURE;
        }

        $delta = (float) $this->option('delta');
        if ($delta < 0) $delta = 0;
        if ($delta > 1) $delta = 1;

        $playerOpt = $this->option('player');
        $limit = (int) $this->option('limit');
        if ($limit <= 0) $limit = 50;

        $q = DB::table('players')
            ->select(['id', 'sportmonks_player_id'])
            ->whereNotNull('sportmonks_player_id')
            ->orderBy('id');

        if ($playerOpt !== null) {
            $q->where('id', (int) $playerOpt);
        } else {
            $q->limit($limit);
        }

        $players = $q->get();
        if ($players->isEmpty()) {
            $this->info('No players found to sync');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $batch = [];
        $ok = 0;
        $fail = 0;

        foreach ($players as $p) {
            $smId = (int) $p->sportmonks_player_id;
            $playerId = (int) $p->id;

            $res = Http::timeout(25)->get("https://api.sportmonks.com/v3/football/players/{$smId}", [
                'api_token' => $token,
                'include' => 'statistics.details',
                'filters' => 'playerStatisticDetailTypes:119',
            ]);

            if (!$res->ok()) {
                $fail++;
                continue;
            }

            $stats = $res->json('data.statistics');
            if (is_array($stats) && array_key_exists('data', $stats) && is_array($stats['data'])) {
                $stats = $stats['data'];
            }
            if (!is_array($stats)) {
                $stats = [];
            }

            $seasonMinutes = [];

            foreach ($stats as $st) {
                if (!is_array($st)) continue;

                $seasonId = $st['season_id'] ?? null;
                if (!$seasonId) continue;

                $details = $st['details'] ?? [];
                if (is_array($details) && array_key_exists('data', $details) && is_array($details['data'])) {
                    $details = $details['data'];
                }
                if (!is_array($details)) $details = [];

                foreach ($details as $d) {
                    if (!is_array($d)) continue;
                    $val = $d['value'] ?? null;

                    $m = 0;
                    if (is_array($val) && array_key_exists('total', $val)) {
                        $m = (int) $val['total'];
                    } elseif (is_numeric($val)) {
                        $m = (int) $val;
                    }

                    if ($m > 0) {
                        $sid = (int) $seasonId;
                        $seasonMinutes[$sid] = ($seasonMinutes[$sid] ?? 0) + $m;
                    }
                }
            }

            $minutesLongTerm = 0;

            if (!empty($seasonMinutes)) {
                $seasonIds = array_keys($seasonMinutes);
                rsort($seasonIds);

                $s1 = $seasonIds[0];
                $m1 = (int) ($seasonMinutes[$s1] ?? 0);

                $m2 = 0;
                if (count($seasonIds) > 1) {
                    $s2 = $seasonIds[1];
                    $m2 = (int) ($seasonMinutes[$s2] ?? 0);
                }

                $minutesLongTerm = (int) round($m1 + ($delta * $m2));
            }

            $batch[] = [
                'player_id' => $playerId,
                'minutes_long_term' => $minutesLongTerm,
                'updated_at' => $now,
                'created_at' => $now,
            ];
            $ok++;
        }

        if ($batch) {
            DB::table('player_reputation_stats')->upsert(
                $batch,
                ['player_id'],
                ['minutes_long_term', 'updated_at']
            );
        }

        $this->info('delta=' . $delta . ' ok=' . $ok . ' fail=' . $fail);

        return self::SUCCESS;
    }
}
