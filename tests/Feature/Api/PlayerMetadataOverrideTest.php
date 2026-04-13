<?php

namespace Tests\Feature\Api;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlayerMetadataOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_manual_number_override(): void
    {
        $playerId = $this->createPlayer([
            'name' => 'Thiago Rodrigues',
            'slug' => 'thiago-rodrigues',
            'number' => 9,
            'fd_name' => 'Thiago Rodrigues',
            'fd_number' => 19,
            'manual_display_name' => null,
            'manual_number' => null,
        ]);

        $exitCode = Artisan::call('zcout:set-player-manual-metadata', [
            'playerId' => $playerId,
            '--number' => 5,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('players', [
            'id' => $playerId,
            'manual_number' => 5,
        ]);

        $player = Player::query()->findOrFail($playerId);

        $this->assertSame(5, $player->effective_number);
    }

    public function test_it_sets_manual_name_override(): void
    {
        $playerId = $this->createPlayer([
            'name' => 'Thiago Rodrigues',
            'slug' => 'thiago-rodrigues',
            'number' => 9,
            'fd_name' => 'Thiago Rodrigues',
            'fd_number' => 9,
            'manual_display_name' => null,
            'manual_number' => null,
        ]);

        $exitCode = Artisan::call('zcout:set-player-manual-metadata', [
            'playerId' => $playerId,
            '--name' => 'Igor Thiago',
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('players', [
            'id' => $playerId,
            'manual_display_name' => 'Igor Thiago',
        ]);

        $player = Player::query()->findOrFail($playerId);

        $this->assertSame('Igor Thiago', $player->effective_name);
    }

    public function test_it_clears_manual_overrides_and_falls_back_to_football_data_fields(): void
    {
        $playerId = $this->createPlayer([
            'name' => 'Thiago Rodrigues',
            'slug' => 'thiago-rodrigues',
            'number' => 9,
            'fd_name' => 'Thiago Rodrigues',
            'fd_number' => 19,
            'manual_display_name' => 'Igor Thiago',
            'manual_number' => 5,
        ]);

        $exitCode = Artisan::call('zcout:set-player-manual-metadata', [
            'playerId' => $playerId,
            '--clear-name' => true,
            '--clear-number' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('players', [
            'id' => $playerId,
            'manual_display_name' => null,
            'manual_number' => null,
        ]);

        $player = Player::query()->findOrFail($playerId);

        $this->assertSame('Thiago Rodrigues', $player->effective_name);
        $this->assertSame(19, $player->effective_number);
    }

    private function createPlayer(array $overrides = []): int
    {
        return DB::table('players')->insertGetId(array_merge([
            'name' => 'Test Player',
            'slug' => 'test-player',
            'club' => null,
            'number' => null,
            'club_id' => null,
            'country_id' => null,
            'external_id' => null,
            'sportmonks_player_id' => null,
            'date_of_birth' => null,
            'position_id' => null,
            'fpl_element_id' => null,
            'fd_name' => null,
            'fd_number' => null,
            'fd_synced_at' => null,
            'manual_display_name' => null,
            'manual_number' => null,
        ], $overrides));
    }
}
