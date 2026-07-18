<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuelControllerCalibrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_returns_calibration_obvious_duel(): void
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'code' => 'ENG',
            'name' => 'ENGLAND',
            'iso2' => 'GB',
            'flag_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubAId = DB::table('clubs')->insertGetId([
            'name' => 'Club A',
            'slug' => 'club-a',
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'color_tertiary' => '#FFFFFF',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubBId = DB::table('clubs')->insertGetId([
            'name' => 'Club B',
            'slug' => 'club-b',
            'color_primary' => '#333333',
            'color_secondary' => '#444444',
            'color_tertiary' => '#FFFFFF',
            'is_current_premier_league' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a',
            'club' => 'Club A',
            'number' => 2,
            'club_id' => $clubAId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b',
            'club' => 'Club B',
            'number' => 22,
            'club_id' => $clubBId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        DB::table('player_reputation_stats')->insert([
            [
                'player_id' => $playerAId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.0000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 45,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
            [
                'player_id' => $playerBId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.1000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 46,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
        ]);

        DB::table('player_attribute_ratings')->insert([
            [
                'player_id' => $playerAId,
                'attribute_id' => $attributeId,
                'rating' => 30,
                'votes_count' => 0,
                'confidence' => 50,
            ],
            [
                'player_id' => $playerBId,
                'attribute_id' => $attributeId,
                'rating' => 60,
                'votes_count' => 0,
                'confidence' => 50,
            ],
        ]);

        $response = $this
            ->withHeaders(['X-Zcout-Anon' => 'calibration-test-001'])
            ->getJson('/api/duels/next?debug=1&attribute=pace&intent=calibration');

        $response->assertOk();

        $response->assertJsonPath('attribute.key', 'pace');
        $response->assertJsonPath('matchmaking.category', 'obvious');
        $response->assertJsonPath('matchmaking.intent', 'calibration');
        $response->assertJsonPath('matchmaking.tier', null);
        $response->assertJsonPath('matchmaking.positional_mode', null);
        $response->assertJsonPath('matchmaking.gap_profile', null);

        $response->assertJsonPath('debug.requested.attribute', 'pace');
        $response->assertJsonPath('debug.requested.intent', 'calibration');
        $response->assertJsonPath('debug.picked.intent', 'calibration');

        $this->assertGreaterThanOrEqual(25, (float) data_get($response->json(), 'debug.gap'));
    }
}
