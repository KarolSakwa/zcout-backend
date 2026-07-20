<?php

namespace Tests\Feature\PremierLeague;

use App\Matchmaking\MatchmakingCandidateRowFetcher;
use App\Models\Player;
use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPremierLeagueSeasonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zcout_premier_league.expected_club_count' => 20]);
        putenv('FOOTBALL_DATA_TOKEN=test-token');
        $_ENV['FOOTBALL_DATA_TOKEN'] = 'test-token';
        $_SERVER['FOOTBALL_DATA_TOKEN'] = 'test-token';
    }

    public function test_sync_creates_updates_deactivates_clubs_and_preserves_ids(): void
    {
        $stayingClubId = DB::table('clubs')->insertGetId([
            'external_id' => 1,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $relegatedClubId = DB::table('clubs')->insertGetId([
            'external_id' => 90,
            'name' => 'West Ham United FC',
            'slug' => 'west-ham-united-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = $this->createPosition();
        $stayingPlayerId = DB::table('players')->insertGetId([
            'external_id' => 1001,
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
            'club_id' => $stayingClubId,
            'club' => 'Arsenal FC',
            'position_id' => $positionId,
        ]);

        $relegatedPlayerId = DB::table('players')->insertGetId([
            'external_id' => 2001,
            'name' => 'West Ham Player',
            'slug' => 'west-ham-player',
            'club_id' => $relegatedClubId,
            'club' => 'West Ham United FC',
            'position_id' => $positionId,
        ]);

        $transferPlayerId = DB::table('players')->insertGetId([
            'external_id' => 3001,
            'name' => 'Transfer Player',
            'slug' => 'transfer-player',
            'club_id' => $relegatedClubId,
            'club' => 'West Ham United FC',
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $transferPlayerId,
            'attribute_id' => $attributeId,
            'rating' => 77.5,
            'votes_count' => 3,
            'rating_weight_sum' => 3,
            'confidence_weight_sum' => 3,
            'confidence' => 40,
        ]);

        $votesBefore = 1;
        $userId = (int) \App\Models\User::factory()->create()->id;
        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $attributeId,
            'duel_id' => null,
            'player_a_id' => $transferPlayerId,
            'player_b_id' => null,
            'winner_id' => null,
            'user_id' => $userId,
            'voter_hash' => null,
            'value' => 80,
            'weight_applied' => 1,
            'confidence_weight_applied' => 1,
            'weight_version' => 1,
            'created_at' => now(),
        ]);

        $this->fakeTwentyClubsApi(
            stayingClubExternalId: 1,
            stayingClubName: 'Arsenal FC',
            promotedExternalId: 91,
            promotedName: 'Coventry City FC',
            squadOverrides: [
                1 => [
                    ['id' => 1001, 'name' => 'Bukayo Saka', 'position' => 'Right Back', 'nationality' => 'ENG'],
                    ['id' => 3001, 'name' => 'Transfer Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
                ],
                91 => [
                    ['id' => 4001, 'name' => 'New Coventry Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
                ],
            ],
        );

        $report = $this->synchronizer()->sync(dryRun: false, detachMissingPlayers: false, sleepSeconds: 0);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($stayingClubId, (int) DB::table('clubs')->where('external_id', 1)->value('id'));
        $this->assertSame($relegatedClubId, (int) DB::table('clubs')->where('external_id', 90)->value('id'));
        $this->assertTrue((bool) DB::table('clubs')->where('id', $stayingClubId)->value('is_current_premier_league'));
        $this->assertFalse((bool) DB::table('clubs')->where('id', $relegatedClubId)->value('is_current_premier_league'));
        $this->assertSame(20, DB::table('clubs')->where('is_current_premier_league', true)->count());
        $this->assertDatabaseHas('clubs', ['external_id' => 91, 'name' => 'Coventry City FC', 'is_current_premier_league' => true]);

        $this->assertSame($stayingPlayerId, (int) DB::table('players')->where('external_id', 1001)->value('id'));
        $this->assertSame($relegatedPlayerId, (int) DB::table('players')->where('external_id', 2001)->value('id'));
        $this->assertSame($transferPlayerId, (int) DB::table('players')->where('external_id', 3001)->value('id'));
        $this->assertSame($stayingClubId, (int) DB::table('players')->where('id', $transferPlayerId)->value('club_id'));
        $this->assertSame($relegatedClubId, (int) DB::table('players')->where('id', $relegatedPlayerId)->value('club_id'));

        $rating = DB::table('player_attribute_ratings')->where('player_id', $transferPlayerId)->first();
        $this->assertSame('77.500', (string) $rating->rating);
        $this->assertSame(40, (int) $rating->confidence);
        $this->assertSame($votesBefore, DB::table('votes')->count());
        $this->assertTrue(Player::query()->whereKey($transferPlayerId)->inCurrentPremierLeague()->exists());
        $this->assertFalse(Player::query()->whereKey($relegatedPlayerId)->inCurrentPremierLeague()->exists());
        $this->assertNotNull(DB::table('players')->where('external_id', 4001)->value('id'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        DB::table('clubs')->insert([
            'external_id' => 1,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC');

        $beforeClubs = DB::table('clubs')->count();
        $beforePlayers = DB::table('players')->count();

        $report = $this->synchronizer()->sync(dryRun: true);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertTrue($report->dryRun);
        $this->assertFalse($report->applied);
        $this->assertSame($beforeClubs, DB::table('clubs')->count());
        $this->assertSame($beforePlayers, DB::table('players')->count());
        $this->assertFalse((bool) DB::table('clubs')->where('external_id', 1)->value('is_current_premier_league'));
        $this->assertSame(0, DB::table('clubs')->where('external_id', 91)->count());
    }

    public function test_aborts_when_api_returns_wrong_club_count(): void
    {
        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response([
                'teams' => [
                    ['id' => 1, 'name' => 'Arsenal FC'],
                    ['id' => 2, 'name' => 'Chelsea FC'],
                ],
            ], 200),
        ]);

        $report = $this->synchronizer()->sync();

        $this->assertFalse($report->success);
        $this->assertNotEmpty($report->errors);
        $this->assertStringContainsString('expected exactly 20', $report->errors[0]);
    }

    public function test_aborts_on_duplicate_club_external_id(): void
    {
        $teams = [];
        for ($i = 1; $i <= 19; $i++) {
            $teams[] = ['id' => $i, 'name' => "Club {$i}"];
        }
        $teams[] = ['id' => 1, 'name' => 'Duplicate'];

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response(['teams' => $teams], 200),
        ]);

        $report = $this->synchronizer()->sync();
        $this->assertFalse($report->success);
        $this->assertStringContainsString('duplicate club external_id', $report->errors[0]);
    }

    public function test_aborts_when_squad_fetch_fails_without_db_changes(): void
    {
        $teams = [];
        for ($i = 1; $i <= 20; $i++) {
            $teams[] = ['id' => $i, 'name' => "Club {$i} FC"];
        }

        Http::fake(function ($request) use ($teams) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }
            if (str_contains($request->url(), '/teams/2')) {
                return Http::response(['message' => 'error'], 500);
            }
            if (preg_match('#/teams/(\d+)$#', $request->url(), $m)) {
                return Http::response(['squad' => []], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        DB::table('clubs')->insert([
            'external_id' => 99,
            'name' => 'Old Club',
            'slug' => 'old-club',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->synchronizer()->sync();
        $this->assertFalse($report->success);
        $this->assertTrue((bool) DB::table('clubs')->where('external_id', 99)->value('is_current_premier_league'));
        $this->assertSame(0, DB::table('clubs')->where('external_id', 1)->count());
    }

    public function test_name_conflict_with_different_external_id_blocks_merge(): void
    {
        DB::table('clubs')->insert([
            'external_id' => 999,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC');

        $report = $this->synchronizer()->sync();
        $this->assertFalse($report->success);
        $this->assertTrue(
            collect($report->errors)->contains(fn ($e) => str_contains($e, 'Club create blocked') || str_contains($e, 'Club name conflict'))
        );
        $this->assertSame(0, DB::table('clubs')->where('external_id', 1)->count());
    }

    public function test_player_name_conflict_with_different_external_id_does_not_merge(): void
    {
        $clubId = DB::table('clubs')->insertGetId([
            'external_id' => 1,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existingId = DB::table('players')->insertGetId([
            'external_id' => 5555,
            'name' => 'Same Name Player',
            'slug' => 'same-name-player',
            'club_id' => $clubId,
        ]);

        $this->fakeTwentyClubsApi(
            1,
            'Arsenal FC',
            91,
            'Coventry City FC',
            [
                1 => [
                    ['id' => 7777, 'name' => 'Same Name Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
                ],
            ],
        );

        $report = $this->synchronizer()->sync();
        $this->assertFalse($report->success);
        $this->assertSame($existingId, (int) DB::table('players')->where('external_id', 5555)->value('id'));
        $this->assertSame(0, DB::table('players')->where('external_id', 7777)->count());
    }

    public function test_idempotent_second_run_creates_no_new_ids(): void
    {
        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC', [
            1 => [
                ['id' => 1001, 'name' => 'Bukayo Saka', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            91 => [
                ['id' => 4001, 'name' => 'New Coventry Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $this->createPosition();
        $first = $this->synchronizer()->sync();
        $this->assertTrue($first->success, implode('; ', $first->errors));

        $clubMap = DB::table('clubs')->whereNotNull('external_id')->pluck('id', 'external_id')->all();
        $playerMap = DB::table('players')->whereNotNull('external_id')->pluck('id', 'external_id')->all();
        $history = [
            'players' => DB::table('players')->count(),
            'clubs' => DB::table('clubs')->count(),
            'votes' => DB::table('votes')->count(),
            'ratings' => DB::table('player_attribute_ratings')->count(),
        ];

        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC', [
            1 => [
                ['id' => 1001, 'name' => 'Bukayo Saka', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
            91 => [
                ['id' => 4001, 'name' => 'New Coventry Player', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $second = $this->synchronizer()->sync();
        $this->assertTrue($second->success, implode('; ', $second->errors));
        $this->assertSame(0, $second->counts['clubs_create'] ?? -1);
        $this->assertSame(0, $second->counts['players_create'] ?? -1);

        $clubMapAfter = DB::table('clubs')->whereNotNull('external_id')->pluck('id', 'external_id')->all();
        $playerMapAfter = DB::table('players')->whereNotNull('external_id')->pluck('id', 'external_id')->all();
        ksort($clubMap);
        ksort($clubMapAfter);
        ksort($playerMap);
        ksort($playerMapAfter);

        $this->assertSame($clubMap, $clubMapAfter);
        $this->assertSame($playerMap, $playerMapAfter);
        $this->assertSame($history['players'], DB::table('players')->count());
        $this->assertSame($history['clubs'], DB::table('clubs')->count());
    }

    public function test_matchmaking_excludes_inactive_club_players_and_resume_expires(): void
    {
        $activeClubId = DB::table('clubs')->insertGetId([
            'name' => 'Active',
            'slug' => 'active',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveClubId = DB::table('clubs')->insertGetId([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->createPosition();
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $activeA = DB::table('players')->insertGetId([
            'name' => 'Active A',
            'slug' => 'active-a',
            'club_id' => $activeClubId,
            'position_id' => $positionId,
        ]);
        $activeB = DB::table('players')->insertGetId([
            'name' => 'Active B',
            'slug' => 'active-b',
            'club_id' => $activeClubId,
            'position_id' => $positionId,
        ]);
        $inactivePlayer = DB::table('players')->insertGetId([
            'name' => 'Inactive P',
            'slug' => 'inactive-p',
            'club_id' => $inactiveClubId,
            'position_id' => $positionId,
        ]);

        foreach ([$activeA, $activeB, $inactivePlayer] as $pid) {
            DB::table('player_reputation_stats')->insert([
                'player_id' => $pid,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'tier' => 'C',
            ]);
        }

        $rows = app(MatchmakingCandidateRowFetcher::class)->handle([
            'attribute_id' => $attributeId,
            'intent' => 'calibration',
            'force_gk' => false,
        ]);

        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($activeA, $ids);
        $this->assertContains($activeB, $ids);
        $this->assertNotContains($inactivePlayer, $ids);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $activeA,
            'player_b_id' => $inactivePlayer,
            'created_at' => now(),
        ]);
        DB::table('voter_duel_locks')->insert([
            'voter_hash' => str_repeat('a', 64),
            'duel_id' => $duelId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resume = app(\App\Actions\ResumeLockedDuelAction::class)->handle([
            'voter_hash' => str_repeat('a', 64),
            'vote_voter_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame('expired', $resume['status']);
        $this->assertSame(0, DB::table('voter_duel_locks')->count());
    }

    public function test_baseline_loader_and_seed_fallback_use_active_pool_only(): void
    {
        $activeClubId = DB::table('clubs')->insertGetId([
            'name' => 'Active',
            'slug' => 'active',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveClubId = DB::table('clubs')->insertGetId([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->createPosition();
        $activePlayer = DB::table('players')->insertGetId([
            'name' => 'Active Player',
            'slug' => 'active-player',
            'club_id' => $activeClubId,
            'position_id' => $positionId,
        ]);
        $inactivePlayer = DB::table('players')->insertGetId([
            'name' => 'Inactive Player',
            'slug' => 'inactive-player',
            'club_id' => $inactiveClubId,
            'position_id' => $positionId,
        ]);

        DB::table('attributes')->insert([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $path = storage_path('framework/testing/baseline_sync_test.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'format_version' => 2,
            'competition' => 'Premier League',
            'season' => '2026/27',
            'players' => [],
        ]));

        $result = app(\App\Actions\InitializePlayerAttributeRatingsFromBaselineJsonAction::class)->execute($path);

        $this->assertGreaterThan(0, $result['rows_initialized']);
        $this->assertSame(1, $result['players_count']);
        $this->assertDatabaseHas('player_attribute_ratings', ['player_id' => $activePlayer]);
        $this->assertDatabaseMissing('player_attribute_ratings', ['player_id' => $inactivePlayer]);

        $command = app(\App\Console\Commands\ZcoutBaselineEditCommand::class);
        $method = new \ReflectionMethod($command, 'loadPlayers');
        $method->setAccessible(true);
        $players = $method->invoke($command);
        $ids = collect($players)->pluck('id')->all();
        $this->assertContains($activePlayer, $ids);
        $this->assertNotContains($inactivePlayer, $ids);
    }

    public function test_detach_missing_players_only_with_flag(): void
    {
        $clubId = DB::table('clubs')->insertGetId([
            'external_id' => 1,
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->createPosition();
        $missingId = DB::table('players')->insertGetId([
            'external_id' => 8888,
            'name' => 'Missing From Squad',
            'slug' => 'missing-from-squad',
            'club_id' => $clubId,
            'position_id' => $positionId,
        ]);

        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC', [
            1 => [
                ['id' => 1001, 'name' => 'Bukayo Saka', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $withoutDetach = $this->synchronizer()->sync(detachMissingPlayers: false);
        $this->assertTrue($withoutDetach->success, implode('; ', $withoutDetach->errors));
        $this->assertSame($clubId, (int) DB::table('players')->where('id', $missingId)->value('club_id'));

        $this->fakeTwentyClubsApi(1, 'Arsenal FC', 91, 'Coventry City FC', [
            1 => [
                ['id' => 1001, 'name' => 'Bukayo Saka', 'position' => 'Right Back', 'nationality' => 'ENG'],
            ],
        ]);

        $withDetach = $this->synchronizer()->sync(detachMissingPlayers: true);
        $this->assertTrue($withDetach->success, implode('; ', $withDetach->errors));
        $this->assertNull(DB::table('players')->where('id', $missingId)->value('club_id'));
        $this->assertSame($missingId, (int) DB::table('players')->where('external_id', 8888)->value('id'));
    }

    private function synchronizer(): PremierLeagueSeasonSynchronizer
    {
        return new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token'));
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

    /**
     * @param  array<int, list<array<string, mixed>>>  $squadOverrides
     */
    private function fakeTwentyClubsApi(
        int $stayingClubExternalId,
        string $stayingClubName,
        int $promotedExternalId,
        string $promotedName,
        array $squadOverrides = [],
    ): void {
        $teams = [
            ['id' => $stayingClubExternalId, 'name' => $stayingClubName],
            ['id' => $promotedExternalId, 'name' => $promotedName],
        ];

        for ($i = 2; $i <= 19; $i++) {
            $teams[] = ['id' => 100 + $i, 'name' => "Filler Club {$i} FC"];
        }

        Http::fake(function ($request) use ($teams, $squadOverrides) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }

            if (preg_match('#/teams/(\d+)$#', $request->url(), $m)) {
                $ext = (int) $m[1];
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

            return Http::response(['message' => 'unexpected '.$request->url()], 404);
        });
    }

    public function test_initializing_attribute_ratings_only_inserts_missing_rows(): void
{
    $clubId = DB::table('clubs')->insertGetId([
        'name' => 'Active',
        'slug' => 'active',
        'is_current_premier_league' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $positionId = $this->createPosition();

    $existingPlayerId = DB::table('players')->insertGetId([
        'name' => 'Existing Player',
        'slug' => 'existing-player',
        'club_id' => $clubId,
        'position_id' => $positionId,
    ]);

    $newPlayerId = DB::table('players')->insertGetId([
        'name' => 'New Player',
        'slug' => 'new-player',
        'club_id' => $clubId,
        'position_id' => $positionId,
    ]);

    $attributeId = DB::table('attributes')->insertGetId([
        'key' => 'pace',
        'label' => 'Pace',
        'group' => 'PACE',
        'order' => 1,
        'scope' => 'both',
    ]);

    DB::table('player_attribute_ratings')->insert([
        'player_id' => $existingPlayerId,
        'attribute_id' => $attributeId,
        'rating' => 82.5,
        'votes_count' => 17,
        'rating_weight_sum' => 14.5,
        'confidence_weight_sum' => 12.5,
        'confidence' => 0.73,
        'last_vote_at' => now()->subDay(),
    ]);

    $path = storage_path('framework/testing/baseline_insert_missing_test.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, json_encode([
        'format_version' => 2,
        'competition' => 'Premier League',
        'season' => '2026/27',
        'players' => [],
    ]));

    $result = app(
        \App\Actions\InitializePlayerAttributeRatingsFromBaselineJsonAction::class
    )->execute($path);

    $existingRating = DB::table('player_attribute_ratings')
        ->where('player_id', $existingPlayerId)
        ->where('attribute_id', $attributeId)
        ->first();

    $this->assertSame('82.500', (string) $existingRating->rating);
    $this->assertSame(17, (int) $existingRating->votes_count);
    $this->assertSame('14.500', (string) $existingRating->rating_weight_sum);
    $this->assertSame('12.5000', (string) $existingRating->confidence_weight_sum);
    $this->assertSame('0.73', (string) $existingRating->confidence);
    $this->assertNotNull($existingRating->last_vote_at);

    $this->assertDatabaseHas('player_attribute_ratings', [
        'player_id' => $newPlayerId,
        'attribute_id' => $attributeId,
        'votes_count' => 0,
    ]);

    $this->assertSame(1, $result['rows_initialized']);
}
}
