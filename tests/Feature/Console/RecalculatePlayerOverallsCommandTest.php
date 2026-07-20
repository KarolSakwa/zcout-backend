<?php

namespace Tests\Feature\Console;

use App\Actions\RecalculatePlayerOverallAction;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecalculatePlayerOverallsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_position_change_updates_existing_record_instead_of_creating_duplicate(): void
    {
        $midPositionId = $this->createPosition('MID', 'mid', 'Midfielder');
        $cmPositionId = $this->createPosition('CM', 'cm', 'Central Midfielder');

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Position Change Player',
            'slug' => 'position-change-player',
            'fd_position_id' => $midPositionId,
        ]);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'MID',
            'overall' => 70.00,
            'confidence' => 5.00,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('players')->where('id', $playerId)->update(['fd_position_id' => $cmPositionId]);

        app(RecalculatePlayerOverallAction::class)->execute(Player::query()->findOrFail($playerId));

        $this->assertSame(1, DB::table('player_overalls')->where('player_id', $playerId)->count());
        $this->assertSame('CM', DB::table('player_overalls')->where('player_id', $playerId)->value('position'));
    }

    public function test_command_is_idempotent_and_does_not_increase_record_count(): void
    {
        $positionId = $this->createPosition('RW', 'rw', 'Right Winger');

        foreach (range(1, 3) as $index) {
            DB::table('players')->insert([
                'name' => "Overall Player {$index}",
                'slug' => "overall-player-{$index}",
                'fd_position_id' => $positionId,
            ]);
        }

        Artisan::call('zcout:recalculate-player-overalls');
        $countAfterFirstRun = DB::table('player_overalls')->count();

        Artisan::call('zcout:recalculate-player-overalls');
        $countAfterSecondRun = DB::table('player_overalls')->count();

        $this->assertSame(3, $countAfterFirstRun);
        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
        $this->assertSame(3, Player::query()->count());
    }

    public function test_new_player_receives_single_overall_record(): void
    {
        $positionId = $this->createPosition('ST', 'st', 'Striker');

        $playerId = DB::table('players')->insertGetId([
            'name' => 'New Striker',
            'slug' => 'new-striker',
            'fd_position_id' => $positionId,
        ]);

        Artisan::call('zcout:recalculate-player-overalls');

        $this->assertSame(1, DB::table('player_overalls')->where('player_id', $playerId)->count());
        $this->assertSame('ST', DB::table('player_overalls')->where('player_id', $playerId)->value('position'));
    }

    public function test_unique_player_id_constraint_prevents_second_overall_record(): void
    {
        $positionId = $this->createPosition('CB', 'cb', 'Centre Back');

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Defender',
            'slug' => 'defender-unique-overall',
            'fd_position_id' => $positionId,
        ]);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'CB',
            'overall' => 75.00,
            'confidence' => 6.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'RB',
            'overall' => 74.00,
            'confidence' => 6.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPosition(string $shortLabel, string $key, string $label): int
    {
        return (int) DB::table('positions')->insertGetId([
            'short_label' => $shortLabel,
            'key' => $key,
            'label' => $label,
            'group' => 'MID',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
