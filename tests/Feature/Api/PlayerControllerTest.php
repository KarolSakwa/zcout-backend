<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCurrentPremierLeagueClub;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use CreatesCurrentPremierLeagueClub;
    use RefreshDatabase;

    public function test_show_returns_player_profile_contract(): void
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'RW',
            'key' => 'rw',
            'label' => 'Right Winger',
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
            'fd_position_id' => $positionId,
        ]);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'RW',
            'overall' => 82.5,
            'confidence' => 8.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/players/{$playerId}");

        $response
            ->assertOk()
            ->assertJsonStructure([
                'id',
                'name',
                'slug',
                'archetype',
                'number',
                'date_of_birth',
                'position',
                'club',
                'country',
                'overall_confidence',
                'overall_trend_7d',
                'radar_axes',
                'attributes',
                'overall',
                'previous_player_id',
                'next_player_id',
                'rank',
            ])
            ->assertJsonPath('id', $playerId)
            ->assertJsonPath('name', 'Bukayo Saka')
            ->assertJsonPath('slug', 'bukayo-saka');
    }

    public function test_featured_returns_player_profile_contract(): void
    {
        $clubId = $this->createCurrentPremierLeagueClub('Featured Club', 'featured-club');

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'AM',
            'key' => 'am',
            'label' => 'Attacking Midfielder',
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'fd_position_id' => $positionId,
            'club_id' => $clubId,
        ]);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'AM',
            'overall' => 84.0,
            'confidence' => 12.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('player_reputation_stats')->insert([
            'player_id' => $playerId,
            'player_rep' => 0.9000,
            'tier' => 'A',
            'minutes_90d' => 100,
            'minutes_long_term' => 1000,
            'is_long_tail' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'fpl_now_cost' => 45,
            'fpl_selected_by_percent' => 0,
        ]);

        $response = $this->getJson('/api/players/featured');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'id',
                'name',
                'slug',
                'archetype',
                'number',
                'date_of_birth',
                'position',
                'club',
                'country',
                'overall_confidence',
                'overall_trend_7d',
                'radar_axes',
                'attributes',
                'overall',
                'previous_player_id',
                'next_player_id',
                'rank',
            ])
            ->assertJsonPath('id', $playerId);
    }

    public function test_featured_does_not_return_player_from_inactive_club(): void
    {
        $inactiveClubId = (int) DB::table('clubs')->insertGetId([
            'name' => 'Relegated Club',
            'slug' => 'relegated-club',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'ST',
            'key' => 'st',
            'label' => 'Striker',
        ]);

        $playerId = (int) DB::table('players')->insertGetId([
            'name' => 'Inactive Club Star',
            'slug' => 'inactive-club-star',
            'fd_position_id' => $positionId,
            'club_id' => $inactiveClubId,
        ]);

        DB::table('player_reputation_stats')->insert([
            'player_id' => $playerId,
            'player_rep' => 0.9500,
            'tier' => 'A',
            'minutes_90d' => 100,
            'minutes_long_term' => 1000,
            'is_long_tail' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'fpl_now_cost' => 50,
            'fpl_selected_by_percent' => 0,
        ]);

        $this->getJson('/api/players/featured')->assertNotFound();
    }
}
