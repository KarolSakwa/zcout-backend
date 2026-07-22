<?php

namespace App\Console\Commands\DataSync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SyncFootballDataPlayerMetadata extends Command
{
    protected $signature = 'zcout:sync-football-data-player-metadata {--team= : Football-data team external id}';

    protected $description = 'Sync football-data player metadata into fd_* fields only';

    public function handle(): int
    {
        $teamFilter = $this->option('team');

        $teams = DB::table('clubs')
            ->select(['id', 'name', 'external_id'])
            ->whereNotNull('external_id')
            ->when($teamFilter, fn ($q) => $q->where('external_id', (int) $teamFilter))
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            $this->warn('No clubs found to sync.');
            return self::SUCCESS;
        }

        $updated = 0;
        $failedTeams = 0;

        foreach ($teams as $team) {
            $res = Http::timeout(25)
                ->withHeaders([
                    'X-Auth-Token' => env('FOOTBALL_DATA_TOKEN'),
                ])
                ->get("https://api.football-data.org/v4/teams/{$team->external_id}");

            if (!$res->ok()) {
                $failedTeams++;
                $this->warn("HTTP {$res->status()} for {$team->name} ({$team->external_id})");
                continue;
            }

            $squad = $res->json('squad') ?? [];

            if (!is_array($squad)) {
                $failedTeams++;
                $this->warn("Invalid squad payload for {$team->name} ({$team->external_id})");
                continue;
            }

            foreach ($squad as $player) {
                $playerExtId = (int) ($player['id'] ?? 0);
                $fdName = trim((string) ($player['name'] ?? ''));
                $fdNumber = $this->parseNumber($player['shirtNumber'] ?? null);

                if ($playerExtId <= 0 || $fdName === '') {
                    continue;
                }

                $affected = DB::table('players')
                    ->where('external_id', $playerExtId)
                    ->update([
                        'fd_name' => $fdName,
                        'fd_number' => $fdNumber,
                        'fd_synced_at' => now(),
                    ]);

                $updated += $affected;
            }

            $this->info("Synced {$team->name}");
            sleep(7);
        }

        $this->info("Updated rows: {$updated}, failed teams: {$failedTeams}");

        return self::SUCCESS;
    }

    private function parseNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\d+/', $value, $m)) {
            $number = (int) $m[0];
            return $number > 0 ? $number : null;
        }

        return null;
    }
}
