<?php

namespace Tests\Feature\PremierLeague;

use App\Models\Player;
use App\PremierLeague\PremierLeagueApiClient;
use App\PremierLeague\PremierLeagueSeasonSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPremierLeaguePlayerOverrideTest extends TestCase
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

    public function test_manual_number_survives_api_null(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_number' => 7,
            'fd_number' => 9,
            'number' => 7,
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Test Player',
            'position' => 'Right Back',
            'nationality' => 'ENG',
            'shirtNumber' => null,
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $row = DB::table('players')->where('id', $playerId)->first();
        $this->assertSame(7, (int) $row->manual_number);
        $this->assertSame(9, (int) $row->fd_number);
        $this->assertSame(7, Player::query()->findOrFail($playerId)->effective_number);
    }

    public function test_manual_number_survives_missing_shirt_number_field(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_number' => 7,
            'fd_number' => 9,
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Test Player',
            'position' => 'Right Back',
            'nationality' => 'ENG',
            // no shirtNumber key
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $row = DB::table('players')->where('id', $playerId)->first();
        $this->assertSame(7, (int) $row->manual_number);
        $this->assertSame(9, (int) $row->fd_number);
        $this->assertSame(7, Player::query()->findOrFail($playerId)->effective_number);
    }

    public function test_manual_number_keeps_effective_when_api_returns_new_number(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_number' => 7,
            'fd_number' => 9,
            'number' => 7,
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Test Player',
            'position' => 'Right Back',
            'nationality' => 'ENG',
            'shirtNumber' => 11,
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $row = DB::table('players')->where('id', $playerId)->first();
        $this->assertSame(7, (int) $row->manual_number);
        $this->assertSame(11, (int) $row->fd_number);
        $this->assertSame(7, Player::query()->findOrFail($playerId)->effective_number);
    }

    public function test_without_manual_number_app_uses_api_fd_number(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_number' => null,
            'fd_number' => null,
            'number' => null,
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Test Player',
            'position' => 'Right Back',
            'nationality' => 'ENG',
            'shirtNumber' => 11,
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $player = Player::query()->findOrFail($playerId);
        $this->assertNull($player->manual_number);
        $this->assertSame(11, (int) $player->fd_number);
        $this->assertSame(11, $player->effective_number);
    }

    public function test_manual_display_name_survives_shorter_api_name(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'name' => 'Gabriel Martinelli',
            'slug' => 'gabriel-martinelli',
            'manual_display_name' => 'Gabriel Martinelli',
            'fd_name' => 'Martinelli',
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Martinelli',
            'position' => 'Right Back',
            'nationality' => 'BRA',
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $player = Player::query()->findOrFail($playerId);
        $this->assertSame('Gabriel Martinelli', $player->manual_display_name);
        $this->assertSame('Martinelli', $player->fd_name);
        $this->assertSame('Gabriel Martinelli', $player->effective_name);
        $this->assertSame('gabriel-martinelli', $player->slug);
        $this->assertSame('Gabriel Martinelli', $player->name);
        $this->assertSame($playerId, (int) DB::table('players')->where('external_id', 1001)->value('id'));
    }

    public function test_second_sync_does_not_remove_name_override_or_change_slug(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'name' => 'Gabriel Martinelli',
            'slug' => 'gabriel-martinelli',
            'manual_display_name' => 'Gabriel Martinelli',
            'fd_name' => 'Martinelli',
        ]);

        $payload = [
            'id' => 1001,
            'name' => 'G. Martinelli',
            'position' => 'Right Back',
            'nationality' => 'BRA',
        ];

        $this->fakeApiForPlayer(1001, $payload);
        $this->assertTrue($this->synchronizer()->sync()->success);

        $this->fakeApiForPlayer(1001, $payload);
        $this->assertTrue($this->synchronizer()->sync()->success);

        $player = Player::query()->findOrFail($playerId);
        $this->assertSame('Gabriel Martinelli', $player->manual_display_name);
        $this->assertSame('G. Martinelli', $player->fd_name);
        $this->assertSame('Gabriel Martinelli', $player->effective_name);
        $this->assertSame('gabriel-martinelli', $player->slug);
        $this->assertSame($playerId, (int) $player->id);
    }

    public function test_manual_position_survives_api_position_and_transfer(): void
    {
        $lwId = $this->createPosition('LW', 'Left Wing', 'LW', 1);
        $apiPosId = $this->createPosition('LWM', 'Left Winger', 'LWM', 2);
        $rbId = $this->createPosition('RB', 'Right Back', 'RB', 3);

        $clubA = $this->createClub(1, 'Arsenal FC');
        $clubB = $this->createClub(2, 'Chelsea FC', active: false);

        $playerId = (int) DB::table('players')->insertGetId([
            'external_id' => 1001,
            'name' => 'Wing Player',
            'slug' => 'wing-player',
            'club_id' => $clubA,
            'club' => 'Arsenal FC',
            'position_id' => $rbId,
            'fd_position_id' => $apiPosId,
            'manual_position_id' => $lwId,
        ]);

        $this->fakeApiTransfer(1001, fromClubExt: 1, toClubExt: 2, squadPlayer: [
            'id' => 1001,
            'name' => 'Wing Player',
            'position' => 'Left Winger',
            'nationality' => 'ENG',
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $player = Player::query()->findOrFail($playerId);
        $this->assertSame($lwId, (int) $player->manual_position_id);
        $this->assertSame($apiPosId, (int) $player->fd_position_id);
        $this->assertSame($rbId, (int) $player->position_id);
        $this->assertSame($lwId, (int) $player->effective_position_id);
        $this->assertSame('LW', $player->effective_position_short);
        $this->assertSame($clubB, (int) $player->club_id);

        $this->fakeApiTransfer(1001, fromClubExt: 1, toClubExt: 2, squadPlayer: [
            'id' => 1001,
            'name' => 'Wing Player',
            'position' => 'Left Winger',
            'nationality' => 'ENG',
        ]);
        $this->assertTrue($this->synchronizer()->sync()->success);
        $this->assertSame($lwId, (int) Player::query()->findOrFail($playerId)->manual_position_id);
        $this->assertSame('LW', Player::query()->findOrFail($playerId)->effective_position_short);
    }

    public function test_without_manual_position_uses_normalized_api_fd_position(): void
    {
        $apiPosId = $this->createPosition('LWM', 'Left Winger', 'LWM', 1);
        $this->createPosition('RB', 'Right Back', 'RB', 2);

        [$playerId] = $this->seedPlayerWithOverrides([
            'position_id' => null,
            'fd_position_id' => null,
            'manual_position_id' => null,
        ], createDefaultRb: true);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Test Player',
            'position' => 'Left Winger',
            'nationality' => 'ENG',
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $player = Player::query()->findOrFail($playerId);
        $this->assertNull($player->manual_position_id);
        $this->assertSame($apiPosId, (int) $player->fd_position_id);
        $this->assertSame($apiPosId, (int) $player->effective_position_id);
        $this->assertSame('LWM', $player->effective_position_short);
    }

    public function test_api_null_does_not_clear_any_override_fields(): void
    {
        $lwId = $this->createPosition('LW', 'Left Wing', 'LW', 1);
        $this->createPosition('RB', 'Right Back', 'RB', 2);

        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_display_name' => 'Gabriel Martinelli',
            'manual_number' => 11,
            'manual_position_id' => $lwId,
            'fd_name' => 'Martinelli',
            'fd_number' => 7,
            'fd_position_id' => null,
            'number' => 11,
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Martinelli',
            'position' => null,
            'nationality' => 'BRA',
            'shirtNumber' => null,
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);

        $row = DB::table('players')->where('id', $playerId)->first();
        $this->assertSame('Gabriel Martinelli', $row->manual_display_name);
        $this->assertSame(11, (int) $row->manual_number);
        $this->assertSame($lwId, (int) $row->manual_position_id);
        $this->assertSame('Martinelli', $row->fd_name);
        $this->assertSame(7, (int) $row->fd_number);
        $this->assertSame('Gabriel Martinelli', Player::query()->findOrFail($playerId)->effective_name);
        $this->assertSame(11, Player::query()->findOrFail($playerId)->effective_number);
        $this->assertSame('LW', Player::query()->findOrFail($playerId)->effective_position_short);
    }

    public function test_dry_run_does_not_change_raw_or_effective_fields(): void
    {
        [$playerId] = $this->seedPlayerWithOverrides([
            'manual_display_name' => 'Gabriel Martinelli',
            'manual_number' => 11,
            'fd_name' => 'Old Fd',
            'fd_number' => 9,
            'slug' => 'gabriel-martinelli',
        ]);

        $before = (array) DB::table('players')->where('id', $playerId)->first();

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Martinelli',
            'position' => 'Right Back',
            'nationality' => 'BRA',
            'shirtNumber' => 99,
        ]);

        $report = $this->synchronizer()->sync(dryRun: true);
        $this->assertTrue($report->success);
        $this->assertFalse($report->applied);

        $after = (array) DB::table('players')->where('id', $playerId)->first();
        $this->assertSame($before, $after);
    }

    public function test_sync_preserves_ratings_votes_and_player_id(): void
    {
        [$playerId, $clubId] = $this->seedPlayerWithOverrides([
            'manual_display_name' => 'Gabriel Martinelli',
            'manual_number' => 11,
        ]);

        $attributeId = (int) DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $playerId,
            'attribute_id' => $attributeId,
            'rating' => 88.25,
            'votes_count' => 4,
            'rating_weight_sum' => 4,
            'confidence_weight_sum' => 40,
            'confidence' => 55,
        ]);

        $userId = (int) \App\Models\User::factory()->create()->id;
        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $attributeId,
            'duel_id' => null,
            'player_a_id' => $playerId,
            'player_b_id' => null,
            'winner_id' => null,
            'user_id' => $userId,
            'voter_hash' => null,
            'value' => 90,
            'weight_applied' => 1,
            'confidence_weight_applied' => 1,
            'weight_version' => 1,
            'created_at' => now(),
        ]);

        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Martinelli',
            'position' => 'Right Back',
            'nationality' => 'BRA',
            'shirtNumber' => 7,
        ]);

        $this->assertTrue($this->synchronizer()->sync()->success);
        $this->fakeApiForPlayer(1001, [
            'id' => 1001,
            'name' => 'Martinelli',
            'position' => 'Right Back',
            'nationality' => 'BRA',
            'shirtNumber' => 7,
        ]);
        $this->assertTrue($this->synchronizer()->sync()->success);

        $this->assertSame($playerId, (int) DB::table('players')->where('external_id', 1001)->value('id'));
        $this->assertSame($clubId, (int) DB::table('players')->where('id', $playerId)->value('club_id'));
        $rating = DB::table('player_attribute_ratings')->where('player_id', $playerId)->first();
        $this->assertSame('88.250', (string) $rating->rating);
        $this->assertSame(55, (int) $rating->confidence);
        $this->assertSame(1, DB::table('votes')->where('player_a_id', $playerId)->count());
        $this->assertSame('Gabriel Martinelli', Player::query()->findOrFail($playerId)->effective_name);
        $this->assertSame(11, Player::query()->findOrFail($playerId)->effective_number);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: int, 1: int}
     */
    private function seedPlayerWithOverrides(array $overrides, bool $createDefaultRb = true): array
    {
        $clubId = $this->createClub(1, 'Arsenal FC');
        if ($createDefaultRb && ! array_key_exists('position_id', $overrides)) {
            $overrides['position_id'] = $this->createPosition('RB', 'Right Back', 'RB', 1);
        } elseif ($createDefaultRb) {
            $this->createPosition('RB', 'Right Back', 'RB', 99);
        }

        $playerId = (int) DB::table('players')->insertGetId(array_merge([
            'external_id' => 1001,
            'name' => 'Test Player',
            'slug' => 'test-player',
            'club_id' => $clubId,
            'club' => 'Arsenal FC',
            'number' => null,
            'fd_name' => null,
            'fd_number' => null,
            'fd_position_id' => null,
            'manual_display_name' => null,
            'manual_number' => null,
            'manual_position_id' => null,
            'position_id' => null,
        ], $overrides));

        return [$playerId, $clubId];
    }

    private function createClub(int $externalId, string $name, bool $active = true): int
    {
        return (int) DB::table('clubs')->insertGetId([
            'external_id' => $externalId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.$externalId,
            'is_current_premier_league' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosition(string $key, string $label, string $short, int $order): int
    {
        $existing = DB::table('positions')->where('key', $key)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('positions')->insertGetId([
            'key' => $key,
            'label' => $label,
            'short_label' => $short,
            'group' => 'ATT',
            'order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $squadPlayer
     */
    private function fakeApiForPlayer(int $playerExt, array $squadPlayer): void
    {
        $teams = [['id' => 1, 'name' => 'Arsenal FC']];
        for ($i = 2; $i <= 20; $i++) {
            $teams[] = ['id' => 100 + $i, 'name' => "Filler Club {$i} FC"];
        }

        Http::fake(function ($request) use ($teams, $playerExt, $squadPlayer) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }
            if (preg_match('#/teams/(\d+)$#', $request->url(), $m)) {
                $ext = (int) $m[1];
                $squad = $ext === 1
                    ? [$squadPlayer]
                    : [[
                        'id' => 50000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Right Back',
                        'nationality' => 'ENG',
                    ]];

                return Http::response(['squad' => $squad], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });
    }

    /**
     * @param  array<string, mixed>  $squadPlayer
     */
    private function fakeApiTransfer(int $playerExt, int $fromClubExt, int $toClubExt, array $squadPlayer): void
    {
        $teams = [
            ['id' => $fromClubExt, 'name' => 'Arsenal FC'],
            ['id' => $toClubExt, 'name' => 'Chelsea FC'],
        ];
        for ($i = 3; $i <= 20; $i++) {
            $teams[] = ['id' => 100 + $i, 'name' => "Filler Club {$i} FC"];
        }

        // Ensure Chelsea exists as active PL club in API set
        Http::fake(function ($request) use ($teams, $toClubExt, $squadPlayer) {
            if (str_contains($request->url(), '/competitions/PL/teams')) {
                return Http::response(['teams' => $teams], 200);
            }
            if (preg_match('#/teams/(\d+)$#', $request->url(), $m)) {
                $ext = (int) $m[1];
                $squad = $ext === $toClubExt
                    ? [$squadPlayer]
                    : [[
                        'id' => 50000 + $ext,
                        'name' => "Squad Player {$ext}",
                        'position' => 'Right Back',
                        'nationality' => 'ENG',
                    ]];

                return Http::response(['squad' => $squad], 200);
            }

            return Http::response(['message' => 'unexpected'], 404);
        });
    }

    private function synchronizer(): PremierLeagueSeasonSynchronizer
    {
        return new PremierLeagueSeasonSynchronizer(new PremierLeagueApiClient('test-token'));
    }
}
