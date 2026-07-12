<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageNeedsMoreRatingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_tier_a_and_b_players_with_lowest_confidence(): void
    {
        $clubId = DB::table('clubs')->insertGetId([
            'name' => 'Arsenal',
            'slug' => 'arsenal',
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'RW',
            'key' => 'rw',
            'label' => 'Right Winger',
            'group' => 'ATT',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Bukayo Saka',
            'slug' => 'bukayo-saka',
            'club_id' => $clubId,
            'fd_position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Cole Palmer',
            'slug' => 'cole-palmer',
            'club' => 'Chelsea',
            'fd_position_id' => $positionId,
        ]);

        $playerCId = DB::table('players')->insertGetId([
            'name' => 'Unknown Prospect',
            'slug' => 'unknown-prospect',
            'fd_position_id' => $positionId,
        ]);

        $reputationRow = [
            'minutes_90d' => 100,
            'minutes_long_term' => 1000,
            'is_long_tail' => false,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'fpl_now_cost' => 45,
            'fpl_selected_by_percent' => 0,
        ];

        DB::table('player_reputation_stats')->insert([
            array_merge($reputationRow, [
                'player_id' => $playerAId,
                'player_rep' => 0.9000,
                'tier' => 'A',
            ]),
            array_merge($reputationRow, [
                'player_id' => $playerBId,
                'player_rep' => 0.7000,
                'tier' => 'B',
            ]),
            array_merge($reputationRow, [
                'player_id' => $playerCId,
                'player_rep' => 0.5000,
                'tier' => 'C',
            ]),
        ]);

        DB::table('player_overalls')->insert([
            [
                'player_id' => $playerAId,
                'position' => 'RW',
                'overall' => 82.5,
                'confidence' => 8.5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'player_id' => $playerBId,
                'position' => 'RW',
                'overall' => 80.0,
                'confidence' => 12.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'player_id' => $playerCId,
                'position' => 'RW',
                'overall' => 70.0,
                'confidence' => 3.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/homepage/needs-more-ratings?limit=5');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonStructure([
                'items' => [
                    '*' => [
                        'id',
                        'playerId',
                        'player',
                        'slug',
                        'club',
                        'position',
                        'overall',
                        'confidence',
                    ],
                ],
            ])
            ->assertJsonPath('items.0.playerId', $playerAId)
            ->assertJsonPath('items.0.player', 'Bukayo Saka')
            ->assertJsonPath('items.0.slug', 'bukayo-saka')
            ->assertJsonPath('items.0.club', 'Arsenal')
            ->assertJsonPath('items.0.position', 'RW')
            ->assertJsonPath('items.0.overall', 82.5)
            ->assertJsonPath('items.0.confidence', 8.5)
            ->assertJsonPath('items.1.playerId', $playerBId)
            ->assertJsonPath('items.1.slug', 'cole-palmer')
            ->assertJsonPath('items.1.club', 'Chelsea');
    }
}
