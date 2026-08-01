<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCurrentPremierLeagueClub;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use CreatesCurrentPremierLeagueClub;
    use RefreshDatabase;

    public function test_returns_active_premier_league_player(): void
    {
        $clubId = $this->createCurrentPremierLeagueClub('Arsenal FC', 'arsenal-fc-search');

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'RW',
            'key' => 'rw',
            'label' => 'Right Winger',
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Active Search Player',
            'slug' => 'active-search-player',
            'fd_position_id' => $positionId,
            'club_id' => $clubId,
        ]);

        $response = $this->getJson('/api/search?q=active+search+player');

        $response
            ->assertOk()
            ->assertJsonPath('query', 'active search player')
            ->assertJsonFragment([
                'id' => $playerId,
                'name' => 'Active Search Player',
                'slug' => 'active-search-player',
                'club' => 'Arsenal FC',
            ]);
    }

    public function test_returns_player_with_null_club_id(): void
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'ST',
            'key' => 'st',
            'label' => 'Striker',
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Departed Search Player',
            'slug' => 'departed-search-player',
            'fd_position_id' => $positionId,
            'club_id' => null,
        ]);

        $response = $this->getJson('/api/search?q=departed+search+player');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $playerId,
                'name' => 'Departed Search Player',
            ]);
    }

    public function test_player_without_club_has_null_club_field(): void
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
        ]);

        DB::table('players')->insertGetId([
            'name' => 'Clubless Search Player',
            'slug' => 'clubless-search-player',
            'fd_position_id' => $positionId,
            'club_id' => null,
        ]);

        $response = $this->getJson('/api/search?q=clubless+search+player');

        $response
            ->assertOk()
            ->assertJsonPath('players.0.club', null);
    }

    public function test_excludes_player_assigned_to_inactive_club(): void
    {
        $inactiveClubId = DB::table('clubs')->insertGetId([
            'name' => 'Relegated Search FC',
            'slug' => 'relegated-search-fc',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CB',
            'key' => 'cb',
            'label' => 'Centre Back',
        ]);

        DB::table('players')->insertGetId([
            'name' => 'Inactive Club Search Player',
            'slug' => 'inactive-club-search-player',
            'fd_position_id' => $positionId,
            'club_id' => $inactiveClubId,
        ]);

        $response = $this->getJson('/api/search?q=inactive+club+search+player');

        $response
            ->assertOk()
            ->assertJsonPath('players', []);
    }

    public function test_excludes_inactive_club_from_clubs_section(): void
    {
        DB::table('clubs')->insertGetId([
            'name' => 'Inactive Search Club FC',
            'slug' => 'inactive-search-club-fc',
            'is_current_premier_league' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/search?q=inactive+search+club');

        $response
            ->assertOk()
            ->assertJsonPath('clubs', []);
    }

    public function test_player_overall_is_fetched_by_player_id_without_position_filter(): void
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'RW',
            'key' => 'rw',
            'label' => 'Right Winger',
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Overall Search Player',
            'slug' => 'overall-search-player',
            'fd_position_id' => $positionId,
            'club_id' => null,
        ]);

        DB::table('player_overalls')->insert([
            'player_id' => $playerId,
            'position' => 'ATT',
            'overall' => 77.5,
            'confidence' => 5.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/search?q=overall+search+player');

        $response
            ->assertOk()
            ->assertJsonPath('players.0.overall', 77.5);
    }

    public function test_empty_query_returns_empty_arrays(): void
    {
        $response = $this->getJson('/api/search');

        $response
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('players', [])
            ->assertJsonPath('clubs', []);

        $response = $this->getJson('/api/search?q=');

        $response
            ->assertOk()
            ->assertJsonPath('query', '')
            ->assertJsonPath('players', [])
            ->assertJsonPath('clubs', []);
    }
}
