<?php

namespace Tests\Feature\PremierLeague;

use App\Actions\Rankings\BuildFeaturedRankingPayloadAction;
use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use App\PremierLeague\PremierLeagueSyncReport;
use App\Services\Ranking\AttributeRankingProjectionWriter;
use App\Services\Ranking\RebuildRankingProjectionsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RedisProjectionSeasonCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['zcout_premier_league.expected_club_count' => 20]);
        putenv('FOOTBALL_DATA_TOKEN=test-token');
        $_ENV['FOOTBALL_DATA_TOKEN'] = 'test-token';
        $_SERVER['FOOTBALL_DATA_TOKEN'] = 'test-token';

        $this->clearProjectionKeys();
    }

    protected function tearDown(): void
    {
        $this->clearProjectionKeys();
        parent::tearDown();
    }

    public function test_rebuild_removes_inactive_club_players_from_all_ranking_projections(): void
    {
        [$activeClubId, $inactiveClubId] = $this->seedTwoClubs();
        [$playerA, $playerB, $attributeId] = $this->seedPlayersAndRatings($activeClubId, $inactiveClubId);

        Redis::zadd('ranking:pace', 90.0, (string) $playerA);
        Redis::zadd('ranking:pace', 80.0, (string) $playerB);
        Redis::hset('ranking:pace:meta', (string) $playerA, json_encode(['confidence' => 70.0]));
        Redis::hset('ranking:pace:meta', (string) $playerB, json_encode(['confidence' => 60.0]));
        Redis::zadd('ranking:overall', 88.0, (string) $playerA);
        Redis::zadd('ranking:overall', 77.0, (string) $playerB);

        DB::table('clubs')->where('id', $inactiveClubId)->update([
            'is_current_premier_league' => false,
        ]);

        app(RebuildRankingProjectionsAction::class)->handle();

        $this->assertRedisZMemberPresent('ranking:pace', (string) $playerA);
        $this->assertRedisZMemberMissing('ranking:pace', (string) $playerB);
        $this->assertRedisHashFieldPresent('ranking:pace:meta', (string) $playerA);
        $this->assertRedisHashFieldMissing('ranking:pace:meta', (string) $playerB);
        $this->assertRedisZMemberPresent('ranking:overall', (string) $playerA);
        $this->assertRedisZMemberMissing('ranking:overall', (string) $playerB);
        $this->assertEqualsWithDelta(90.0, (float) Redis::zscore('ranking:pace', (string) $playerA), 0.001);

        $meta = json_decode((string) Redis::hget('ranking:pace:meta', (string) $playerA), true);
        $this->assertSame(70.0, (float) $meta['confidence']);

        // Idempotent second rebuild
        app(RebuildRankingProjectionsAction::class)->handle();
        $this->assertRedisZMemberPresent('ranking:pace', (string) $playerA);
        $this->assertRedisZMemberMissing('ranking:pace', (string) $playerB);
        $this->assertRedisZMemberMissing('ranking:overall', (string) $playerB);

        unset($attributeId);
    }

    public function test_writer_removes_inactive_player_from_attribute_meta_and_overall(): void
    {
        [$activeClubId, $inactiveClubId] = $this->seedTwoClubs();
        [$playerA, $playerB] = $this->seedPlayersAndRatings($activeClubId, $inactiveClubId);

        Redis::zadd('ranking:pace', 80.0, (string) $playerB);
        Redis::hset('ranking:pace:meta', (string) $playerB, json_encode(['confidence' => 55.0]));
        Redis::zadd('ranking:overall', 70.0, (string) $playerB);

        DB::table('clubs')->where('id', $inactiveClubId)->update([
            'is_current_premier_league' => false,
        ]);

        app(AttributeRankingProjectionWriter::class)->upsert('pace', $playerB, 80.0, 55.0);

        $this->assertRedisZMemberMissing('ranking:pace', (string) $playerB);
        $this->assertRedisHashFieldMissing('ranking:pace:meta', (string) $playerB);
        $this->assertRedisZMemberMissing('ranking:overall', (string) $playerB);

        app(AttributeRankingProjectionWriter::class)->upsert('pace', $playerA, 91.5, 84.25);
        $this->assertRedisZMemberPresent('ranking:pace', (string) $playerA);
        $this->assertRedisHashFieldPresent('ranking:pace:meta', (string) $playerA);
    }

    public function test_dry_run_does_not_touch_redis(): void
    {
        [$activeClubId, $inactiveClubId] = $this->seedTwoClubs();
        [, $playerB] = $this->seedPlayersAndRatings($activeClubId, $inactiveClubId);

        Redis::zadd('ranking:pace', 80.0, (string) $playerB);
        Redis::hset('ranking:pace:meta', (string) $playerB, json_encode(['confidence' => 60.0]));
        Redis::zadd('ranking:overall', 77.0, (string) $playerB);

        $this->fakeTwentyClubsApi();

        $beforePaceB = Redis::zscore('ranking:pace', (string) $playerB);
        $beforeMetaB = Redis::hget('ranking:pace:meta', (string) $playerB);
        $beforeOverallB = Redis::zscore('ranking:overall', (string) $playerB);

        $dry = (new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token')))
            ->sync(dryRun: true);
        $this->assertTrue($dry->success);
        $this->assertFalse($dry->applied);

        $this->assertEquals($beforePaceB, Redis::zscore('ranking:pace', (string) $playerB));
        $this->assertSame($beforeMetaB, Redis::hget('ranking:pace:meta', (string) $playerB));
        $this->assertEquals($beforeOverallB, Redis::zscore('ranking:overall', (string) $playerB));
    }

    public function test_failed_club_count_validation_does_not_touch_redis(): void
    {
        [$activeClubId, $inactiveClubId] = $this->seedTwoClubs();
        [, $playerB] = $this->seedPlayersAndRatings($activeClubId, $inactiveClubId);

        Redis::zadd('ranking:pace', 80.0, (string) $playerB);
        Redis::hset('ranking:pace:meta', (string) $playerB, json_encode(['confidence' => 60.0]));
        Redis::zadd('ranking:overall', 77.0, (string) $playerB);

        $beforePaceB = Redis::zscore('ranking:pace', (string) $playerB);
        $beforeMetaB = Redis::hget('ranking:pace:meta', (string) $playerB);
        $beforeOverallB = Redis::zscore('ranking:overall', (string) $playerB);

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response([
                'teams' => [
                    ['id' => 1, 'name' => 'Arsenal FC'],
                    ['id' => 2, 'name' => 'Chelsea FC'],
                ],
            ], 200),
        ]);

        $failed = (new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token')))->sync();
        $this->assertFalse($failed->success);
        $this->assertFalse($failed->applied);
        $this->assertStringContainsString('expected exactly 20', $failed->errors[0] ?? '');

        $this->assertEquals($beforePaceB, Redis::zscore('ranking:pace', (string) $playerB));
        $this->assertSame($beforeMetaB, Redis::hget('ranking:pace:meta', (string) $playerB));
        $this->assertEquals($beforeOverallB, Redis::zscore('ranking:overall', (string) $playerB));
    }

    public function test_rebuild_projections_flag_runs_only_after_successful_sync(): void
    {
        $this->seedTwoClubs();
        $this->fakeTwentyClubsApi();

        Redis::zadd('ranking:pace', 11.0, '999001');
        Redis::hset('ranking:pace:meta', '999001', json_encode(['confidence' => 1.0]));
        Redis::zadd('ranking:overall', 11.0, '999001');

        // Without flag: Redis untouched by sync apply
        $this->artisan('zcout:sync-premier-league')
            ->expectsOutputToContain('Ranking projections require rebuild')
            ->assertExitCode(0);

        $this->assertRedisZMemberPresent('ranking:pace', '999001');
        $this->assertRedisHashFieldPresent('ranking:pace:meta', '999001');
        $this->assertRedisZMemberPresent('ranking:overall', '999001');

        // With flag: orphan member removed by full clear+rebuild
        $this->fakeTwentyClubsApi();
        $this->artisan('zcout:sync-premier-league', ['--rebuild-projections' => true])
            ->assertExitCode(0);

        $this->assertRedisZMemberMissing('ranking:pace', '999001');
        $this->assertRedisHashFieldMissing('ranking:pace:meta', '999001');
        $this->assertRedisZMemberMissing('ranking:overall', '999001');
    }

    public function test_failed_api_fetch_via_command_does_not_rebuild_redis(): void
    {
        Redis::zadd('ranking:pace', 42.0, '888');
        Redis::zadd('ranking:overall', 42.0, '888');

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response(['teams' => []], 500),
        ]);

        $this->artisan('zcout:sync-premier-league', ['--rebuild-projections' => true])
            ->assertExitCode(1);

        $this->assertRedisZMemberPresent('ranking:pace', '888');
        $this->assertRedisZMemberPresent('ranking:overall', '888');
    }

    public function test_featured_ranking_excludes_inactive_after_rebuild(): void
    {
        [$activeClubId, $inactiveClubId] = $this->seedTwoClubs();
        [$playerA, $playerB, $attributeId] = $this->seedPlayersAndRatings($activeClubId, $inactiveClubId);

        Redis::zadd('ranking:pace', 95.0, (string) $playerB);
        Redis::zadd('ranking:pace', 90.0, (string) $playerA);
        Redis::hset('ranking:pace:meta', (string) $playerB, json_encode(['confidence' => 80.0]));
        Redis::hset('ranking:pace:meta', (string) $playerA, json_encode(['confidence' => 70.0]));

        DB::table('clubs')->where('id', $inactiveClubId)->update([
            'is_current_premier_league' => false,
        ]);

        app(RebuildRankingProjectionsAction::class)->handle();

        // Sole attribute in this DB is pace, so featured ranking resolves to it.
        unset($attributeId);
        $payload = app(BuildFeaturedRankingPayloadAction::class)->execute();
        $ids = collect($payload['players'])->pluck('playerId')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($playerA, $ids);
        $this->assertNotContains($playerB, $ids);
    }

    public function test_rebuild_failure_after_sync_does_not_rollback_database(): void
    {
        $this->seedTwoClubs();
        $this->fakeTwentyClubsApi();

        $action = Mockery::mock(RebuildRankingProjectionsAction::class);
        $action->shouldReceive('handle')->once()->andThrow(new \RuntimeException('redis down'));
        $this->app->instance(RebuildRankingProjectionsAction::class, $action);

        $this->artisan('zcout:sync-premier-league', ['--rebuild-projections' => true])
            ->expectsOutputToContain('Redis projection rebuild failed')
            ->expectsOutputToContain('php artisan app:rebuild-attribute-ranking-projection-command')
            ->assertExitCode(1);

        $this->assertSame(20, DB::table('clubs')->where('is_current_premier_league', true)->count());
        $this->assertDatabaseHas('clubs', ['external_id' => 91, 'is_current_premier_league' => true]);
    }

    public function test_dry_run_with_rebuild_flag_does_not_touch_redis(): void
    {
        Redis::zadd('ranking:pace', 42.0, '777');
        Redis::zadd('ranking:overall', 42.0, '777');

        $this->fakeTwentyClubsApi();

        $rebuild = Mockery::mock(RebuildRankingProjectionsAction::class);
        $rebuild->shouldReceive('handle')->never();
        $this->app->instance(RebuildRankingProjectionsAction::class, $rebuild);

        $this->artisan('zcout:sync-premier-league', [
            '--dry-run' => true,
            '--rebuild-projections' => true,
        ])->assertExitCode(0);

        $this->assertRedisZMemberPresent('ranking:pace', '777');
        $this->assertRedisZMemberPresent('ranking:overall', '777');
    }

    public function test_failed_validation_does_not_rebuild_redis(): void
    {
        Redis::zadd('ranking:pace', 42.0, '666');
        Redis::zadd('ranking:overall', 42.0, '666');

        Http::fake([
            'https://api.football-data.org/v4/competitions/PL/teams' => Http::response([
                'teams' => [
                    ['id' => 1, 'name' => 'Arsenal FC'],
                    ['id' => 2, 'name' => 'Chelsea FC'],
                ],
            ], 200),
        ]);

        $rebuild = Mockery::mock(RebuildRankingProjectionsAction::class);
        $rebuild->shouldReceive('handle')->never();
        $this->app->instance(RebuildRankingProjectionsAction::class, $rebuild);

        $this->artisan('zcout:sync-premier-league', ['--rebuild-projections' => true])
            ->assertExitCode(1);

        $this->assertRedisZMemberPresent('ranking:pace', '666');
        $this->assertRedisZMemberPresent('ranking:overall', '666');
    }

    public function test_db_transaction_rollback_does_not_rebuild_redis(): void
    {
        Redis::zadd('ranking:pace', 42.0, '555');

        $synchronizer = Mockery::mock(PremierLeagueSeasonSynchronizer::class);
        $synchronizer->shouldReceive('sync')
            ->once()
            ->andReturn(new PremierLeagueSyncReport(
                success: false,
                dryRun: false,
                errors: ['Transaction rolled back: forced failure'],
                applied: false,
            ));
        $this->app->instance(PremierLeagueSeasonSynchronizer::class, $synchronizer);

        $rebuild = Mockery::mock(RebuildRankingProjectionsAction::class);
        $rebuild->shouldReceive('handle')->never();
        $this->app->instance(RebuildRankingProjectionsAction::class, $rebuild);

        $this->artisan('zcout:sync-premier-league', ['--rebuild-projections' => true])
            ->assertExitCode(1);

        $this->assertRedisZMemberPresent('ranking:pace', '555');
    }

    public function test_verify_only_does_not_rebuild_redis(): void
    {
        Redis::zadd('ranking:pace', 42.0, '444');

        $synchronizer = Mockery::mock(PremierLeagueSeasonSynchronizer::class);
        $synchronizer->shouldReceive('verify')
            ->once()
            ->andReturn([
                'active_clubs_ok' => true,
                'invalid_active_locks' => 0,
            ]);
        $synchronizer->shouldReceive('sync')->never();
        $this->app->instance(PremierLeagueSeasonSynchronizer::class, $synchronizer);

        $rebuild = Mockery::mock(RebuildRankingProjectionsAction::class);
        $rebuild->shouldReceive('handle')->never();
        $this->app->instance(RebuildRankingProjectionsAction::class, $rebuild);

        $this->artisan('zcout:sync-premier-league', [
            '--verify-only' => true,
            '--rebuild-projections' => true,
        ])->assertExitCode(0);

        $this->assertRedisZMemberPresent('ranking:pace', '444');
    }

    private function assertRedisZMemberPresent(string $key, string $member): void
    {
        $score = Redis::zscore($key, $member);
        $this->assertTrue($score !== false && $score !== null, "Expected member {$member} in {$key}");
    }

    private function assertRedisZMemberMissing(string $key, string $member): void
    {
        $score = Redis::zscore($key, $member);
        $this->assertTrue($score === false || $score === null, "Expected missing member {$member} in {$key}");
    }

    private function assertRedisHashFieldPresent(string $key, string $field): void
    {
        $value = Redis::hget($key, $field);
        $this->assertTrue($value !== false && $value !== null, "Expected hash field {$field} in {$key}");
    }

    private function assertRedisHashFieldMissing(string $key, string $field): void
    {
        $value = Redis::hget($key, $field);
        $this->assertTrue($value === false || $value === null, "Expected missing hash field {$field} in {$key}");
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedTwoClubs(): array
    {
        $activeClubId = (int) DB::table('clubs')->insertGetId([
            'name' => 'Active Club',
            'slug' => 'active-club-redis',
            'external_id' => 1,
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inactiveClubId = (int) DB::table('clubs')->insertGetId([
            'name' => 'West Ham United FC',
            'slug' => 'west-ham-united-fc-redis',
            'external_id' => 90,
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$activeClubId, $inactiveClubId];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function seedPlayersAndRatings(int $activeClubId, int $inactiveClubId): array
    {
        $positionId = (int) DB::table('positions')->insertGetId([
            'key' => 'ST',
            'label' => 'Striker',
            'short_label' => 'ST',
            'group' => 'ATT',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attributeId = (int) DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $playerA = (int) DB::table('players')->insertGetId([
            'name' => 'Active Player',
            'slug' => 'active-player-redis',
            'club_id' => $activeClubId,
            'position_id' => $positionId,
        ]);
        $playerB = (int) DB::table('players')->insertGetId([
            'name' => 'West Ham Player',
            'slug' => 'west-ham-player-redis',
            'club_id' => $inactiveClubId,
            'position_id' => $positionId,
        ]);

        foreach ([[$playerA, 90.0, 70.0], [$playerB, 80.0, 60.0]] as [$pid, $rating, $confidence]) {
            DB::table('player_attribute_ratings')->insert([
                'player_id' => $pid,
                'attribute_id' => $attributeId,
                'rating' => $rating,
                'votes_count' => 1,
                'rating_weight_sum' => 1,
                'confidence_weight_sum' => $confidence,
                'confidence' => $confidence,
            ]);
            DB::table('player_overalls')->insert([
                'player_id' => $pid,
                'position' => 'ST',
                'overall' => $rating - 2,
                'confidence' => $confidence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$playerA, $playerB, $attributeId];
    }

    private function clearProjectionKeys(): void
    {
        foreach (['ranking:pace', 'ranking:pace:meta', 'ranking:overall'] as $key) {
            try {
                Redis::del($key);
            } catch (\Throwable) {
                // Redis may be unavailable in some local hosts; Sail tests have Redis.
            }
        }
    }

    private function fakeTwentyClubsApi(): void
    {
        $teams = [
            ['id' => 1, 'name' => 'Arsenal FC'],
            ['id' => 91, 'name' => 'Coventry City FC'],
        ];
        for ($i = 2; $i <= 19; $i++) {
            $teams[] = ['id' => 100 + $i, 'name' => "Filler Club {$i} FC"];
        }

        Http::fake(function ($request) use ($teams) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }
            if (preg_match('#/teams/(\d+)$#', $request->url(), $m)) {
                $ext = (int) $m[1];

                return Http::response([
                    'squad' => [[
                        'id' => 70000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Striker',
                        'nationality' => 'ENG',
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });
    }
}
