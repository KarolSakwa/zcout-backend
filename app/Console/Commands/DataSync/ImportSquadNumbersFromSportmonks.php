<?php

namespace App\Console\Commands\DataSync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportSquadNumbersFromSportmonks extends Command
{
    protected $signature = 'zcout:import-sportmonks-squad {teamId : Sportmonks team id (e.g. Arsenal=19)}';

    protected $description = 'Import jersey numbers from Sportmonks squads and map them to local players using date_of_birth';

    public function handle(): int
    {
        $token = env('SPORTMONKS_TOKEN');
        if (!$token) {
            $this->error('Missing SPORTMONKS_TOKEN in .env');
            return self::FAILURE;
        }

        $teamId = (int) $this->argument('teamId');

        $url = "https://api.sportmonks.com/v3/football/squads/teams/{$teamId}";

        $resp = Http::acceptJson()->get($url, [
            'api_token' => $token,
            'include' => 'player',
        ]);

        if (!$resp->successful()) {
            $this->error("Sportmonks error: HTTP {$resp->status()}");
            return self::FAILURE;
        }

        $data = $resp->json('data', []);
        $this->info('Rows: ' . count($data));

        $updated = 0;
        $skippedNoDob = 0;
        $notFound = 0;

        foreach ($data as $row) {
            $jersey = $row['jersey_number'] ?? null;
            $p = $row['player'] ?? null;
            if (!$p) continue;

            $smPlayerId = (int) ($p['id'] ?? 0);
            $dob = $p['date_of_birth'] ?? null;

            if (!$dob) {
                $skippedNoDob++;
                continue;
            }

            // Match local player by DOB (and only players that don't already have a sportmonks_player_id)
            $localId = DB::table('players')
                ->whereDate('date_of_birth', '=', $dob)
                ->whereNull('sportmonks_player_id')
                ->value('id');

            if (!$localId) {
                // fallback: try match even if sportmonks_player_id already set (maybe rerun)
                $localId = DB::table('players')
                    ->whereDate('date_of_birth', '=', $dob)
                    ->value('id');
            }

            if (!$localId) {
                $notFound++;
                $name = $p['name'] ?? ($p['display_name'] ?? ($p['common_name'] ?? ''));
                $this->warn("No local match: name='{$name}', DOB={$dob}, jersey={$jersey}, smPlayerId={$smPlayerId}");
                continue;
            }

            DB::table('players')
                ->where('id', $localId)
                ->update([
                    'number' => $jersey,
                    'sportmonks_player_id' => $smPlayerId,
                ]);

            $updated++;
        }

        $this->info("Updated: {$updated}, skippedNoDob: {$skippedNoDob}, notFound: {$notFound}");
        return self::SUCCESS;
    }
}
