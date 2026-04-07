<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuelControllerGkAttributeSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_requested_gk_attribute_returns_goalkeeper_duel(): void
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'gk_reflexes',
            'label' => 'Reflexes',
            'group' => 'GOALKEEPING',
            'order' => 1,
            'scope' => 'gk',
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubBId = DB::table('clubs')->insertGetId([
            'name' => 'Club B',
            'slug' => 'club-b',
            'color_primary' => '#333333',
            'color_secondary' => '#444444',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gkPositionId = DB::table('positions')->insertGetId([
            'key' => 'GK',
            'label' => 'Goalkeeper',
            'short_label' => 'GK',
            'group' => 'GK',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Goalkeeper A',
            'slug' => 'goalkeeper-a',
            'club' => 'Club A',
            'number' => 1,
            'club_id' => $clubAId,
            'country_id' => $countryId,
            'position_id' => $gkPositionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Goalkeeper B',
            'slug' => 'goalkeeper-b',
            'club' => 'Club B',
            'number' => 13,
            'club_id' => $clubBId,
            'country_id' => $countryId,
            'position_id' => $gkPositionId,
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
                'rating' => 70,
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
            ->withHeaders(['X-Zcout-Anon' => 'gk-attribute-test-001'])
            ->getJson('/api/duels/next?debug=1&attribute=gk_reflexes&intent=production&gap_profile=medium');

        $response->assertOk();

        $response->assertJsonPath('attribute.key', 'gk_reflexes');
        $response->assertJsonPath('debug.force_gk', true);

        $players = data_get($response->json(), 'players', []);
        $this->assertCount(2, $players);
        $this->assertSame('GK', data_get($players, '0.position'));
        $this->assertSame('GK', data_get($players, '1.position'));
    }
}
