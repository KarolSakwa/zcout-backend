<?php

namespace Tests\Feature\PremierLeague;

use App\Actions\Rankings\BuildRankingAttributeAction;
use App\Matchmaking\MatchmakingCandidateRowFetcher;
use App\Models\Club;
use App\Models\Player;
use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IsCurrentPremierLeagueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_existing_clubs_true_and_defaults_new_clubs_false(): void
    {
        $this->rollbackCurrentPremierLeagueColumn();

        $this->assertFalse(Schema::hasColumn('clubs', 'is_current_premier_league'));

        $existingA = DB::table('clubs')->insertGetId([
            'name' => 'Arsenal FC',
            'slug' => 'arsenal-fc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $existingB = DB::table('clubs')->insertGetId([
            'name' => 'West Ham United FC',
            'slug' => 'west-ham-united-fc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCurrentPremierLeagueMigrationUp();

        $this->assertTrue(Schema::hasColumn('clubs', 'is_current_premier_league'));
        $this->assertTrue((bool) DB::table('clubs')->where('id', $existingA)->value('is_current_premier_league'));
        $this->assertTrue((bool) DB::table('clubs')->where('id', $existingB)->value('is_current_premier_league'));

        $newWithoutFlag = DB::table('clubs')->insertGetId([
            'name' => 'Brand New Club FC',
            'slug' => 'brand-new-club-fc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse((bool) DB::table('clubs')->where('id', $newWithoutFlag)->value('is_current_premier_league'));

        $newExplicitTrue = DB::table('clubs')->insertGetId([
            'name' => 'Explicit Active FC',
            'slug' => 'explicit-active-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue((bool) DB::table('clubs')->where('id', $newExplicitTrue)->value('is_current_premier_league'));

        $this->assertSame(false, $this->columnDefaultIsCurrentPremierLeague());
    }

    public function test_migration_on_empty_clubs_table_sets_false_default_for_future_rows(): void
    {
        $this->rollbackCurrentPremierLeagueColumn();
        $this->assertSame(0, DB::table('clubs')->count());

        $this->runCurrentPremierLeagueMigrationUp();

        $id = DB::table('clubs')->insertGetId([
            'name' => 'After Empty Migration FC',
            'slug' => 'after-empty-migration-fc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse((bool) DB::table('clubs')->where('id', $id)->value('is_current_premier_league'));
        $this->assertSame(false, $this->columnDefaultIsCurrentPremierLeague());
    }

    public function test_after_migration_before_sync_active_pool_matchmaking_and_rankings_stay_populated(): void
    {
        $this->rollbackCurrentPremierLeagueColumn();

        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Pre Sync Club',
            'slug' => 'pre-sync-club',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCurrentPremierLeagueMigrationUp();

        $this->assertTrue((bool) DB::table('clubs')->where('id', $clubId)->value('is_current_premier_league'));

        $positionId = DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $playerA = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a-migration',
            'club_id' => $clubId,
            'position_id' => $positionId,
        ]);
        $playerB = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b-migration',
            'club_id' => $clubId,
            'position_id' => $positionId,
        ]);

        foreach ([$playerA, $playerB] as $pid) {
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
            DB::table('player_attribute_ratings')->insert([
                'player_id' => $pid,
                'attribute_id' => $attributeId,
                'rating' => 70,
                'votes_count' => 0,
                'confidence' => 10,
            ]);
        }

        $this->assertSame(2, Player::query()->inCurrentPremierLeague()->count());
        $this->assertTrue(Club::query()->currentPremierLeague()->whereKey($clubId)->exists());

        $candidates = app(MatchmakingCandidateRowFetcher::class)->handle([
            'attribute_id' => $attributeId,
            'intent' => 'calibration',
            'force_gk' => false,
        ]);
        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($playerA, $candidateIds);
        $this->assertContains($playerB, $candidateIds);

        $ranking = app(BuildRankingAttributeAction::class)->execute(
            attributeKey: 'pace',
            position: '',
            limit: 50,
            page: 1,
            sort: 'rating',
            dir: 'desc',
        );

        $this->assertSame(200, $ranking['status']);
        $rankedIds = collect($ranking['body']['items'] ?? [])
            ->map(fn ($row) => (int) ($row['player']['id'] ?? 0))
            ->filter()
            ->values()
            ->all();

        $this->assertContains($playerA, $rankedIds);
        $this->assertContains($playerB, $rankedIds);
    }

    public function test_sync_after_compatibility_backfill_still_sets_exactly_twenty_active_clubs(): void
    {
        config(['zcout_premier_league.expected_club_count' => 20]);
        putenv('FOOTBALL_DATA_TOKEN=test-token');
        $_ENV['FOOTBALL_DATA_TOKEN'] = 'test-token';
        $_SERVER['FOOTBALL_DATA_TOKEN'] = 'test-token';

        $this->rollbackCurrentPremierLeagueColumn();

        DB::table('clubs')->insert([
            [
                'external_id' => 1,
                'name' => 'Arsenal FC',
                'slug' => 'arsenal-fc',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'external_id' => 90,
                'name' => 'West Ham United FC',
                'slug' => 'west-ham-united-fc',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'external_id' => null,
                'name' => 'Historical Extra FC',
                'slug' => 'historical-extra-fc',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->runCurrentPremierLeagueMigrationUp();

        $this->assertSame(3, DB::table('clubs')->where('is_current_premier_league', true)->count());

        DB::table('positions')->insert([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
                        'id' => 50000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Right Back',
                        'nationality' => 'ENG',
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });

        $report = (new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token')))
            ->sync(dryRun: false);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame(20, DB::table('clubs')->where('is_current_premier_league', true)->count());
        $this->assertFalse((bool) DB::table('clubs')->where('external_id', 90)->value('is_current_premier_league'));
        $this->assertFalse((bool) DB::table('clubs')->where('slug', 'historical-extra-fc')->value('is_current_premier_league'));
        $this->assertTrue((bool) DB::table('clubs')->where('external_id', 1)->value('is_current_premier_league'));
        $this->assertDatabaseHas('clubs', ['slug' => 'west-ham-united-fc']);
        $this->assertDatabaseHas('clubs', ['slug' => 'historical-extra-fc']);
    }

    public function test_migration_down_removes_column_and_keeps_clubs(): void
    {
        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Keep Me FC',
            'slug' => 'keep-me-fc',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('clubs', 'is_current_premier_league'));

        $this->runCurrentPremierLeagueMigrationDown();

        $this->assertFalse(Schema::hasColumn('clubs', 'is_current_premier_league'));
        $this->assertSame(1, DB::table('clubs')->where('id', $clubId)->count());
        $this->assertSame('Keep Me FC', DB::table('clubs')->where('id', $clubId)->value('name'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_18_120000_add_is_current_premier_league_to_clubs_table.php');
    }

    private function rollbackCurrentPremierLeagueColumn(): void
    {
        if (! Schema::hasColumn('clubs', 'is_current_premier_league')) {
            return;
        }

        $this->runCurrentPremierLeagueMigrationDown();
    }

    private function runCurrentPremierLeagueMigrationUp(): void
    {
        $this->migration()->up();
    }

    private function runCurrentPremierLeagueMigrationDown(): void
    {
        $this->migration()->down();
    }

    private function columnDefaultIsCurrentPremierLeague(): bool
    {
        $row = DB::selectOne("
            SELECT column_default
            FROM information_schema.columns
            WHERE table_name = 'clubs'
              AND column_name = 'is_current_premier_league'
        ");

        $default = (string) ($row->column_default ?? '');

        // PostgreSQL typically stores boolean defaults as 'false' / 'true' or false/true.
        return str_contains(strtolower($default), 'true');
    }
}
