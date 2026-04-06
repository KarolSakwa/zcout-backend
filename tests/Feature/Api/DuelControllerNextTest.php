<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuelControllerNextTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_returns_same_locked_duel_on_second_request(): void
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $countryAId = DB::table('countries')->insertGetId([
            'code' => 'POL',
            'name' => 'POLAND',
            'iso2' => 'PL',
            'flag_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $countryBId = DB::table('countries')->insertGetId([
            'code' => 'POR',
            'name' => 'PORTUGAL',
            'iso2' => 'PT',
            'flag_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubAId = DB::table('clubs')->insertGetId([
            'name' => 'Aston Villa FC',
            'slug' => 'aston-villa-fc',
            'color_primary' => '#660033',
            'color_secondary' => '#94BEE5',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubBId = DB::table('clubs')->insertGetId([
            'name' => 'Manchester City FC',
            'slug' => 'manchester-city-fc',
            'color_primary' => '#6CABDD',
            'color_secondary' => '#1C2C5B',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionAId = DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionBId = DB::table('positions')->insertGetId([
            'key' => 'CB',
            'label' => 'Centre Back',
            'short_label' => 'CB',
            'group' => 'DEF',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Matty Cash',
            'slug' => 'matty-cash',
            'club' => 'Aston Villa FC',
            'number' => 2,
            'club_id' => $clubAId,
            'country_id' => $countryAId,
            'position_id' => $positionAId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Ruben Dias',
            'slug' => 'ruben-dias',
            'club' => 'Manchester City FC',
            'number' => 3,
            'club_id' => $clubBId,
            'country_id' => $countryBId,
            'position_id' => $positionBId,
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'created_at' => now(),
        ]);

        DB::table('voter_duel_locks')->insert([
            'voter_hash' => 'resume-test-001',
            'duel_id' => $duelId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withHeaders(['X-Zcout-Anon' => 'resume-test-001'])
            ->getJson('/api/duels/next?debug=1');

        $response
            ->assertOk()
            ->assertJsonPath('duel_id', $duelId)
            ->assertJsonPath('attribute.key', 'pace')
            ->assertJsonPath('players.0.id', $playerAId)
            ->assertJsonPath('players.1.id', $playerBId)
            ->assertJsonPath('matchmaking.category', null)
            ->assertJsonPath('matchmaking.positional_mode', null)
            ->assertJsonPath('matchmaking.intent', null)
            ->assertJsonPath('matchmaking.tier', null)
            ->assertJsonPath('matchmaking.gap_profile', null);

        $response2 = $this
            ->withHeaders(['X-Zcout-Anon' => 'resume-test-001'])
            ->getJson('/api/duels/next?debug=1');

        $response2
            ->assertOk()
            ->assertJsonPath('duel_id', $duelId)
            ->assertJsonPath('matchmaking.intent', null);
    }
}
