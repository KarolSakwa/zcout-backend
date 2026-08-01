<?php

namespace Tests\Feature\PremierLeague;

use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPremierLeaguePlayerClubOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const ASTON_VILLA_EXT = 58;

    private const CHELSEA_EXT = 61;

    private const FULHAM_EXT = 63;

    private const IPSWICH_EXT = 349;

    private const ROGERS_EXT = 82140;

    private const GARNACHO_EXT = 181901;

    private const DIOP_EXT = 8296;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zcout_premier_league.expected_club_count' => 20]);
        putenv('FOOTBALL_DATA_TOKEN=test-token');
        $_ENV['FOOTBALL_DATA_TOKEN'] = 'test-token';
        $_SERVER['FOOTBALL_DATA_TOKEN'] = 'test-token';
    }

    public function test_rogers_override_moves_player_to_chelsea(): void
    {
        $this->seedOverrideConfig();
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);

        $this->fakeApi($this->buildTwentyTeams(), [
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['chelsea'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
        $this->assertStringContainsString('multi-squad conflict resolved', implode('; ', $report->warnings));
    }

    public function test_garnacho_override_moves_player_to_aston_villa(): void
    {
        $this->seedOverrideConfig();
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Alejandro Garnacho', 'alejandro-garnacho', self::GARNACHO_EXT, $clubs['chelsea'], $positionId);

        $this->fakeApi($this->buildTwentyTeams(), [
            self::CHELSEA_EXT => [
                ['id' => self::GARNACHO_EXT, 'name' => 'Alejandro Garnacho', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::ASTON_VILLA_EXT => [
                ['id' => self::GARNACHO_EXT, 'name' => 'Alejandro Garnacho', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['aston_villa'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_diop_override_moves_player_to_ipswich(): void
    {
        $this->seedOverrideConfig();
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Issa Diop', 'issa-diop', self::DIOP_EXT, $clubs['fulham'], $positionId);

        $this->fakeApi($this->buildTwentyTeams(), [
            self::FULHAM_EXT => [
                ['id' => self::DIOP_EXT, 'name' => 'Issa Diop', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::IPSWICH_EXT => [
                ['id' => self::DIOP_EXT, 'name' => 'Issa Diop', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['ipswich'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_reversed_squad_order_does_not_change_override_result(): void
    {
        $this->seedOverrideConfig();
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);

        $teams = array_reverse($this->buildTwentyTeams());

        $this->fakeApi($teams, [
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['chelsea'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_duplicate_without_override_stops_sync_without_db_changes(): void
    {
        config(['zcout_premier_league.player_club_overrides' => []]);
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);
        $clubIdBefore = (int) DB::table('players')->where('id', $playerId)->value('club_id');

        $this->fakeApi($this->buildTwentyTeams(), [
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertFalse($report->success);
        $this->assertStringContainsString('No player_club_overrides entry', implode('; ', $report->errors));
        $this->assertSame($clubIdBefore, (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_override_outside_candidate_squads_stops_sync(): void
    {
        config([
            'zcout_premier_league.player_club_overrides' => [
                self::ROGERS_EXT => self::FULHAM_EXT,
            ],
        ]);
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);
        $clubIdBefore = (int) DB::table('players')->where('id', $playerId)->value('club_id');

        $this->fakeApi($this->buildTwentyTeams(), [
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertFalse($report->success);
        $this->assertStringContainsString('not among squads containing the player', implode('; ', $report->errors));
        $this->assertSame($clubIdBefore, (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_override_outside_api_clubs_stops_sync(): void
    {
        config([
            'zcout_premier_league.player_club_overrides' => [
                self::ROGERS_EXT => 999,
            ],
        ]);
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);
        $clubIdBefore = (int) DB::table('players')->where('id', $playerId)->value('club_id');

        $this->fakeApi($this->buildTwentyTeams(), [
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertFalse($report->success);
        $this->assertStringContainsString('not among the current API Premier League clubs', implode('; ', $report->errors));
        $this->assertSame($clubIdBefore, (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_override_resolved_player_is_not_detached(): void
    {
        $this->seedOverrideConfig();
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Morgan Rogers', 'morgan-rogers', self::ROGERS_EXT, $clubs['aston_villa'], $positionId);

        $this->fakeApi($this->buildTwentyTeams(), [
            self::ASTON_VILLA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            self::CHELSEA_EXT => [
                ['id' => self::ROGERS_EXT, 'name' => 'Morgan Rogers', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: true, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['chelsea'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
        $this->assertNotNull(DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    public function test_single_squad_transfer_still_works_without_override(): void
    {
        config(['zcout_premier_league.player_club_overrides' => []]);
        $clubs = $this->seedClubs();
        $positionId = $this->createPosition();
        $playerId = $this->createPlayer('Transfer Player', 'transfer-player', 70001, $clubs['aston_villa'], $positionId);

        $this->fakeApi($this->buildTwentyTeams(), [
            self::CHELSEA_EXT => [
                ['id' => 70001, 'name' => 'Transfer Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($clubs['chelsea'], (int) DB::table('players')->where('id', $playerId)->value('club_id'));
    }

    private function seedOverrideConfig(): void
    {
        config([
            'zcout_premier_league.player_club_overrides' => [
                self::ROGERS_EXT => self::CHELSEA_EXT,
                self::GARNACHO_EXT => self::ASTON_VILLA_EXT,
                self::DIOP_EXT => self::IPSWICH_EXT,
            ],
        ]);
    }

    /**
     * @return array{aston_villa: int, chelsea: int, fulham: int, ipswich: int}
     */
    private function seedClubs(): array
    {
        return [
            'aston_villa' => $this->createClub('Aston Villa FC', self::ASTON_VILLA_EXT),
            'chelsea' => $this->createClub('Chelsea FC', self::CHELSEA_EXT),
            'fulham' => $this->createClub('Fulham FC', self::FULHAM_EXT),
            'ipswich' => $this->createClub('Ipswich Town FC', self::IPSWICH_EXT),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function buildTwentyTeams(): array
    {
        $teams = [
            ['id' => self::ASTON_VILLA_EXT, 'name' => 'Aston Villa FC'],
            ['id' => self::CHELSEA_EXT, 'name' => 'Chelsea FC'],
            ['id' => self::FULHAM_EXT, 'name' => 'Fulham FC'],
            ['id' => self::IPSWICH_EXT, 'name' => 'Ipswich Town FC'],
        ];

        for ($i = 1; count($teams) < 20; $i++) {
            $id = 9000 + $i;
            $teams[] = ['id' => $id, 'name' => "Filler Club {$id} FC"];
        }

        return $teams;
    }

    /**
     * @param  list<array{id: int, name: string}>  $teams
     * @param  array<int, list<array<string, mixed>>>  $squadOverrides
     */
    private function fakeApi(array $teams, array $squadOverrides = []): void
    {
        Http::fake(function ($request) use ($teams, $squadOverrides) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }

            if (preg_match('#/teams/(\d+)$#', $request->url(), $matches)) {
                $ext = (int) $matches[1];
                $squad = $squadOverrides[$ext] ?? [
                    [
                        'id' => 50000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Right Back',
                        'nationality' => 'ENG',
                    ],
                ];

                return Http::response(['squad' => $squad], 200);
            }

            if (str_contains($request->url(), '/persons/')) {
                return Http::response(['message' => 'persons endpoint must not be called'], 500);
            }

            return Http::response(['message' => 'unexpected '.$request->url()], 404);
        });
    }

    private function synchronizer(): PremierLeagueSeasonSynchronizer
    {
        return new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token'));
    }

    private function createClub(string $name, int $externalId): int
    {
        return (int) DB::table('clubs')->insertGetId([
            'external_id' => $externalId,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosition(): int
    {
        return (int) DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(string $name, string $slug, int $externalId, int $clubId, int $positionId): int
    {
        return (int) DB::table('players')->insertGetId([
            'external_id' => $externalId,
            'name' => $name,
            'slug' => $slug,
            'club_id' => $clubId,
            'position_id' => $positionId,
        ]);
    }
}
