<?php

namespace Tests\Feature\Matchmaking;

use App\Matchmaking\MatchmakingCandidateRowFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MatchmakingCandidateRowFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matchmaking_uses_manual_position_then_base_position_and_ignores_fd_position(): void
    {
        $now = now();

        $cbId = DB::table('positions')->insertGetId([
            'short_label' => 'CB',
            'key' => 'CB',
            'label' => 'Centre-Back',
            'group' => 'DEF',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lbId = DB::table('positions')->insertGetId([
            'short_label' => 'LB',
            'key' => 'LB',
            'label' => 'Left-Back',
            'group' => 'DEF',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $defId = DB::table('positions')->insertGetId([
            'short_label' => 'DEF',
            'key' => 'DEF',
            'label' => 'Defence',
            'group' => 'DEF',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Matchmaking Test Club',
            'slug' => 'matchmaking-test-club',
            'is_current_premier_league' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $basePositionPlayerId = DB::table('players')->insertGetId([
            'name' => 'Base Position Player',
            'slug' => 'base-position-player',
            'club_id' => $clubId,
            'position_id' => $cbId,
            'fd_position_id' => $defId,
            'manual_position_id' => null,
        ]);

        $manualPositionPlayerId = DB::table('players')->insertGetId([
            'name' => 'Manual Position Player',
            'slug' => 'manual-position-player',
            'club_id' => $clubId,
            'position_id' => $cbId,
            'fd_position_id' => $defId,
            'manual_position_id' => $lbId,
        ]);

        DB::table('player_reputation_stats')->insert([
            [
                'player_id' => $basePositionPlayerId,
                'tier' => 'A',
                'player_rep' => 0.50,
                'fpl_now_cost' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'player_id' => $manualPositionPlayerId,
                'tier' => 'A',
                'player_rep' => 0.50,
                'fpl_now_cost' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $rows = app(MatchmakingCandidateRowFetcher::class)
            ->handle([
                'attribute_id' => 999999,
                'intent' => 'production',
                'selected_tier' => 'A',
                'force_gk' => false,
            ])
            ->keyBy('id');

        $this->assertCount(2, $rows);

        $this->assertSame(
            'CB',
            $rows->get($basePositionPlayerId)->pos_short,
            'Matchmaking should use position_id when manual_position_id is null and ignore fd_position_id.'
        );

        $this->assertSame(
            'LB',
            $rows->get($manualPositionPlayerId)->pos_short,
            'manual_position_id should take precedence over position_id and fd_position_id.'
        );
    }
}
