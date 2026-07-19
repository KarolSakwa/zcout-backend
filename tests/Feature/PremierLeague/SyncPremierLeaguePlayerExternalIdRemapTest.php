<?php

namespace Tests\Feature\PremierLeague;

use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPremierLeaguePlayerExternalIdRemapTest extends TestCase
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

    public function test_configured_external_id_remaps_update_existing_players(): void
    {
        $positionId = $this->createPosition();
        $clubId = $this->createClub(1, 'Liverpool FC');

        $remaps = [
            168 => ['old' => 186701, 'new' => 191154, 'name' => 'Stefan Bajcetic'],
            273 => ['old' => 176852, 'new' => 191140, 'name' => 'Lewis Hall'],
            496 => ['old' => 180389, 'new' => 191396, 'name' => 'Adam Wharton'],
        ];

        $attributeId = $this->createAttribute();
        $userId = (int) \App\Models\User::factory()->create()->id;

        foreach ($remaps as $playerId => $data) {
            DB::table('players')->insert([
                'id' => $playerId,
                'external_id' => $data['old'],
                'name' => $data['name'],
                'slug' => str($data['name'])->slug()->toString(),
                'club_id' => $clubId,
                'club' => 'Liverpool FC',
                'position_id' => $positionId,
            ]);

            DB::table('player_attribute_ratings')->insert([
                'player_id' => $playerId,
                'attribute_id' => $attributeId,
                'rating' => 70.0 + $playerId,
                'votes_count' => 2,
                'rating_weight_sum' => 2,
                'confidence_weight_sum' => 20,
                'confidence' => 20,
            ]);

            DB::table('votes')->insert([
                'source' => 'direct',
                'attribute_id' => $attributeId,
                'duel_id' => null,
                'player_a_id' => $playerId,
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
        }

        $squad = [];
        foreach ($remaps as $data) {
            $squad[] = [
                'id' => $data['new'],
                'name' => $data['name'],
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ];
        }

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 91, 'Coventry City FC', [1 => $squad]);

        $report = $this->synchronizer()->sync();

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame(3, $report->counts['players_external_id_remap'] ?? 0);
        $this->assertSame(0, $report->counts['players_create'] ?? -1);

        foreach ($remaps as $playerId => $data) {
            $this->assertSame($playerId, (int) DB::table('players')->where('external_id', $data['new'])->value('id'));
            $this->assertNull(DB::table('players')->where('external_id', $data['old'])->value('id'));
            $rating = DB::table('player_attribute_ratings')->where('player_id', $playerId)->value('rating');
            $this->assertNotNull($rating);
            $this->assertSame(1, DB::table('votes')->where('player_a_id', $playerId)->count());
        }

        $remapLines = collect($report->playerLines)->where('action', 'external_id_remap');
        $this->assertCount(3, $remapLines);
        $this->assertTrue(
            $remapLines->contains(fn (array $line) => $line['player_id'] === 168
                && $line['external_id']['from'] === 186701
                && $line['external_id']['to'] === 191154),
        );

        $this->assertFalse(
            collect($report->playerLines)->contains(fn (array $line) => ($line['action'] ?? '') === 'missing_from_api_squad'
                && in_array($line['player_id'] ?? 0, [168, 273, 496], true)),
        );
    }

    public function test_dry_run_reports_external_id_remap_without_writing(): void
    {
        $clubId = $this->createClub(1, 'Liverpool FC');
        $playerId = 168;

        DB::table('players')->insert([
            'id' => $playerId,
            'external_id' => 186701,
            'name' => 'Stefan Bajcetic',
            'slug' => 'stefan-bajcetic',
            'club_id' => $clubId,
            'club' => 'Liverpool FC',
        ]);

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 91, 'Coventry City FC', [
            1 => [[
                'id' => 191154,
                'name' => 'Stefan Bajcetic',
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ]],
        ]);

        $report = $this->synchronizer()->sync(dryRun: true);

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertTrue($report->dryRun);
        $this->assertFalse($report->applied);
        $this->assertSame(186701, (int) DB::table('players')->where('id', $playerId)->value('external_id'));
        $this->assertTrue(
            collect($report->playerLines)->contains(fn (array $line) => $line['action'] === 'external_id_remap'
                && $line['player_id'] === $playerId),
        );
    }

    public function test_name_match_without_explicit_remap_still_blocks_create(): void
    {
        $clubId = $this->createClub(1, 'Liverpool FC');
        $existingId = DB::table('players')->insertGetId([
            'external_id' => 5555,
            'name' => 'Stefan Bajcetic',
            'slug' => 'stefan-bajcetic-old',
            'club_id' => $clubId,
        ]);

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 91, 'Coventry City FC', [
            1 => [[
                'id' => 999999,
                'name' => 'Stefan Bajcetic',
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ]],
        ]);

        $report = $this->synchronizer()->sync();

        $this->assertFalse($report->success);
        $this->assertStringContainsString('potential duplicate', strtolower($report->errors[0] ?? ''));
        $this->assertSame($existingId, (int) DB::table('players')->where('external_id', 5555)->value('id'));
        $this->assertNull(DB::table('players')->where('external_id', 999999)->value('id'));
    }

    public function test_existing_old_and_new_records_block_sync_before_transaction(): void
    {
        $clubId = $this->createClub(1, 'Liverpool FC');

        DB::table('players')->insert([
            'id' => 168,
            'external_id' => 186701,
            'name' => 'Stefan Bajcetic',
            'slug' => 'stefan-bajcetic',
            'club_id' => $clubId,
        ]);
        DB::table('players')->insert([
            'id' => 900,
            'external_id' => 191154,
            'name' => 'Stefan Bajcetic Duplicate',
            'slug' => 'stefan-bajcetic-duplicate',
            'club_id' => $clubId,
        ]);

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 91, 'Coventry City FC', [
            1 => [[
                'id' => 191154,
                'name' => 'Stefan Bajcetic',
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ]],
        ]);

        $report = $this->synchronizer()->sync();

        $this->assertFalse($report->success);
        $this->assertTrue(
            collect($report->errors)->contains(fn (string $error) => str_contains($error, 'Remap conflict')),
        );
        $this->assertSame(186701, (int) DB::table('players')->where('id', 168)->value('external_id'));
        $this->assertSame(191154, (int) DB::table('players')->where('id', 900)->value('external_id'));
    }

    public function test_invalid_remap_config_with_multiple_old_ids_for_same_new_id_blocks_sync(): void
    {
        config([
            'zcout_premier_league.player_external_id_remaps' => [
                186701 => 191154,
                176852 => 191154,
            ],
        ]);

        $clubId = $this->createClub(1, 'Liverpool FC');
        DB::table('players')->insert([
            'external_id' => 186701,
            'name' => 'Stefan Bajcetic',
            'slug' => 'stefan-bajcetic',
            'club_id' => $clubId,
        ]);

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 91, 'Coventry City FC', [
            1 => [[
                'id' => 191154,
                'name' => 'Stefan Bajcetic',
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ]],
        ]);

        $report = $this->synchronizer()->sync();

        $this->assertFalse($report->success);
        $this->assertTrue(
            collect($report->errors)->contains(fn (string $error) => str_contains($error, 'Invalid player_external_id_remaps')),
        );
    }

    public function test_remap_allows_club_update_for_existing_player(): void
    {
        $oldClubId = $this->createClub(1, 'Liverpool FC');
        $newClubId = $this->createClub(2, 'Chelsea FC');

        $playerId = 168;
        DB::table('players')->insert([
            'id' => $playerId,
            'external_id' => 186701,
            'name' => 'Stefan Bajcetic',
            'slug' => 'stefan-bajcetic',
            'club_id' => $oldClubId,
            'club' => 'Liverpool FC',
        ]);

        $this->fakeTwentyClubsApi(1, 'Liverpool FC', 2, 'Chelsea FC', [
            2 => [[
                'id' => 191154,
                'name' => 'Stefan Bajcetic',
                'position' => 'Right Back',
                'nationality' => 'ENG',
            ]],
        ]);

        $report = $this->synchronizer()->sync();

        $this->assertTrue($report->success, implode('; ', $report->errors));
        $this->assertSame($newClubId, (int) DB::table('players')->where('id', $playerId)->value('club_id'));
        $this->assertSame(191154, (int) DB::table('players')->where('id', $playerId)->value('external_id'));
    }

    protected function tearDown(): void
    {
        $defaults = require base_path('config/zcout_premier_league.php');
        config(['zcout_premier_league.player_external_id_remaps' => $defaults['player_external_id_remaps']]);

        parent::tearDown();
    }

    private function synchronizer(): PremierLeagueSeasonSynchronizer
    {
        return new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token'));
    }

    private function createClub(int $externalId, string $name): int
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

    private function createAttribute(): int
    {
        return (int) DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
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

            if (preg_match('#/teams/(\d+)$#', $request->url(), $matches)) {
                $ext = (int) $matches[1];
                $squad = $squadOverrides[$ext] ?? [];

                return Http::response(['squad' => $squad], 200);
            }

            return Http::response(['message' => 'unexpected '.$request->url()], 404);
        });
    }
}
