<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportPremierLeague extends Command
{
    protected $signature = 'zcout:import-pl
        {--sleep=7 : Seconds to sleep between team requests}
        {--team= : Import only one football-data team external id (e.g. 57)}';

    protected $description = 'Import Premier League clubs + players from football-data.org into local DB';

    private function norm(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '') return null;

        $s = str_replace(["\u{2019}", "\u{2018}", "’", "`"], "'", $s);
        $s = str_replace(["\u{2013}", "\u{2014}"], "-", $s);

        return mb_strtolower($s);
    }

    public function handle(): int
    {
        $token = env('FOOTBALL_DATA_TOKEN');
        if (!$token) {
            $this->error('Missing FOOTBALL_DATA_TOKEN in .env');
            return self::FAILURE;
        }

        $sleepSeconds = (int) $this->option('sleep');
        $onlyTeamExtId = $this->option('team') ? (int) $this->option('team') : null;

        // cache: positions label -> id
        $posIdByLabel = [];
        foreach (DB::table('positions')->select('id', 'label')->get() as $row) {
            $k = $this->norm($row->label);
            if ($k) $posIdByLabel[$k] = (int) $row->id;
        }

        // cache: countries code -> id
        $countryIdByCode = DB::table('countries')->pluck('id', 'code')->toArray();

        $teamsResp = Http::withHeaders(['X-Auth-Token' => $token])
            ->retry(3, 800)
            ->get('https://api.football-data.org/v4/competitions/PL/teams');

        if (!$teamsResp->successful()) {
            $this->error('Failed to fetch PL teams: ' . $teamsResp->status());
            return self::FAILURE;
        }

        $teams = $teamsResp->json('teams', []);
        if ($onlyTeamExtId) {
            $teams = array_values(array_filter($teams, fn ($t) => (int)($t['id'] ?? 0) === $onlyTeamExtId));
        }

        $this->info('Teams: ' . count($teams));

        foreach ($teams as $t) {
            $teamExtId = (int) ($t['id'] ?? 0);
            $teamName  = (string) ($t['name'] ?? '');

            if ($teamExtId === 0 || $teamName === '') {
                continue;
            }

            DB::table('clubs')->upsert(
                [[
                    'external_id' => $teamExtId,
                    'name' => $teamName,
                    'slug' => Str::slug($teamName),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['external_id'],
                ['name', 'slug', 'updated_at']
            );

            $clubId = (int) DB::table('clubs')->where('external_id', $teamExtId)->value('id');

            $teamResp = Http::withHeaders(['X-Auth-Token' => $token])
                ->retry(3, 800)
                ->get("https://api.football-data.org/v4/teams/{$teamExtId}");

            if (!$teamResp->successful()) {
                $this->warn("Failed team {$teamName}: " . $teamResp->status());
                if ($sleepSeconds > 0) sleep($sleepSeconds);
                continue;
            }

            $squad = $teamResp->json('squad', []);
            if (!is_array($squad)) $squad = [];

            // existing cache (żeby nie nadpisywać nullami)
            $extIds = [];
            foreach ($squad as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if ($pid > 0) $extIds[] = $pid;
            }
            $extIds = array_values(array_unique($extIds));

            $existingByExtId = collect();
            if (!empty($extIds)) {
                $existingByExtId = DB::table('players')
                    ->whereIn('external_id', $extIds)
                    ->get(['external_id', 'country_id', 'position_id', 'date_of_birth'])
                    ->keyBy('external_id');
            }

            $rows = [];

            foreach ($squad as $p) {
                $playerExtId = (int) ($p['id'] ?? 0);
                $playerName  = (string) ($p['name'] ?? '');

                if ($playerExtId === 0 || $playerName === '') {
                    continue;
                }

                $existing = $existingByExtId->get($playerExtId);

                // DOB
                $dob = $p['dateOfBirth'] ?? null;
                if (!$dob && $existing && $existing->date_of_birth) {
                    $dob = $existing->date_of_birth;
                }

                // position_id
                $rawPos = $p['position'] ?? null;
                $posId = null;
                if ($rawPos) {
                    $posId = $posIdByLabel[$this->norm((string) $rawPos)] ?? null;
                }
                if (!$posId && $existing && $existing->position_id) {
                    $posId = (int) $existing->position_id;
                }

                // country_id (football-data daje kod w "nationality")
                $code = $p['nationality'] ?? null;
                $countryId = null;

                if (is_string($code)) {
                    $code = strtoupper(trim($code));
                    if ($code !== '') {
                        $countryId = $countryIdByCode[$code] ?? null;

                        if (!$countryId) {
                            DB::table('countries')->updateOrInsert(
                                ['code' => $code],
                                [
                                    'name' => $code, // MVP: na razie tyle; później można uzupełnić ładnymi nazwami
                                    'flag_url' => '/flags/' . strtolower($code) . '.png',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );

                            $countryId = (int) DB::table('countries')->where('code', $code)->value('id');
                            $countryIdByCode[$code] = $countryId;
                        }
                    }
                }

                if (!$countryId && $existing && $existing->country_id) {
                    $countryId = (int) $existing->country_id;
                }

                $rows[] = [
                    'external_id' => $playerExtId,
                    'name' => $playerName,
                    'slug' => Str::slug($playerName),
                    'club_id' => $clubId,
                    'date_of_birth' => $dob,
                    'country_id' => $countryId,
                    'position_id' => $posId,
                ];
            }

            if (!empty($rows)) {
                DB::table('players')->upsert(
                    $rows,
                    ['external_id'],
                    ['name', 'slug', 'club_id', 'date_of_birth', 'country_id', 'position_id']
                );
            }

            $this->info("Imported {$teamName}: " . count($rows) . ' players');

            if ($sleepSeconds > 0) sleep($sleepSeconds);
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
