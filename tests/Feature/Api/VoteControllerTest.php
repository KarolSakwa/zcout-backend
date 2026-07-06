<?php

namespace Tests\Feature\Api;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_duel_vote_returns_expected_contract(): void
    {
        $fixture = $this->createDuelFixture();

        $response = $this->postJson(
            '/api/votes',
            [
                'attribute_key' => 'passing',
                'player_a_id' => $fixture['player_a_id'],
                'player_b_id' => $fixture['player_b_id'],
                'winner_id' => $fixture['player_a_id'],
                'duel_id' => $fixture['duel_id'],
            ],
            [
                'X-Zcout-Anon' => 'vote-controller-anon-1',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonStructure([
                'vote_id',
                'duel_id',
                'attribute_id',
                'players' => [
                    '*' => [
                        'id',
                        'rating_before',
                        'rating_after',
                        'delta',
                        'votes_count',
                        'rating_weight_sum',
                        'confidence_weight_sum',
                        'confidence',
                        'last_vote_at',
                        'attribute_rank',
                        'is_top_ten',
                    ],
                ],
                'popularity' => [
                    'player_a_id',
                    'player_b_id',
                    'votes_a',
                    'votes_b',
                    'votes_total',
                ],
            ])
            ->assertJsonPath('duel_id', $fixture['duel_id'])
            ->assertJsonPath('attribute_id', $fixture['attribute_id'])
            ->assertJsonPath('popularity.votes_total', 1)
            ->assertJsonPath('popularity.votes_a', 1)
            ->assertJsonPath('popularity.votes_b', 0);

        $this->assertDatabaseHas('votes', [
            'source' => 'duel',
            'duel_id' => $fixture['duel_id'],
            'attribute_id' => $fixture['attribute_id'],
            'winner_id' => $fixture['player_a_id'],
        ]);

        $this->assertDatabaseHas('vote_weight_logs', [
            'weight_version' => 1,
            'rating_algorithm_version' => 1,
        ]);
    }

    public function test_store_duel_vote_returns_400_when_voter_id_missing(): void
    {
        $fixture = $this->createDuelFixture();

        $response = $this->postJson('/api/votes', [
            'attribute_key' => 'passing',
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'duel_id' => $fixture['duel_id'],
        ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath('message', 'Missing voter id.');
    }

    public function test_store_duel_vote_returns_422_for_validation_errors(): void
    {
        $response = $this->postJson(
            '/api/votes',
            [],
            ['X-Zcout-Anon' => 'vote-controller-anon-2'],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors']);
    }

    public function test_store_duel_vote_returns_404_when_attribute_not_found(): void
    {
        $fixture = $this->createDuelFixture();

        $response = $this->postJson(
            '/api/votes',
            [
                'attribute_key' => 'nonexistent-attribute',
                'player_a_id' => $fixture['player_a_id'],
                'player_b_id' => $fixture['player_b_id'],
                'winner_id' => $fixture['player_a_id'],
                'duel_id' => $fixture['duel_id'],
            ],
            ['X-Zcout-Anon' => 'vote-controller-anon-3'],
        );

        $response
            ->assertStatus(404)
            ->assertJsonPath('message', 'Attribute not found.');
    }

    public function test_store_duel_vote_returns_404_when_duel_not_found(): void
    {
        $fixture = $this->createDuelFixture();

        $response = $this->postJson(
            '/api/votes',
            [
                'attribute_key' => 'passing',
                'player_a_id' => $fixture['player_a_id'],
                'player_b_id' => $fixture['player_b_id'],
                'winner_id' => $fixture['player_a_id'],
                'duel_id' => 999999,
            ],
            ['X-Zcout-Anon' => 'vote-controller-anon-4'],
        );

        $response
            ->assertStatus(404)
            ->assertJsonPath('message', 'Duel not found.');
    }

    public function test_store_duel_vote_returns_422_when_winner_not_in_duel(): void
    {
        $fixture = $this->createDuelFixture();

        $otherPlayerId = DB::table('players')->insertGetId([
            'name' => 'Other Player',
            'slug' => 'other-player',
            'position_id' => $fixture['position_id'],
        ]);

        $response = $this->postJson(
            '/api/votes',
            [
                'attribute_key' => 'passing',
                'player_a_id' => $fixture['player_a_id'],
                'player_b_id' => $fixture['player_b_id'],
                'winner_id' => $otherPlayerId,
                'duel_id' => $fixture['duel_id'],
            ],
            ['X-Zcout-Anon' => 'vote-controller-anon-5'],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'winner_id must be one of the duel players.');
    }

    public function test_store_duel_vote_returns_409_on_duplicate_vote(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");

        $fixture = $this->createDuelFixture();
        $headers = ['X-Zcout-Anon' => 'vote-controller-anon-duplicate'];

        $payload = [
            'attribute_key' => 'passing',
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'duel_id' => $fixture['duel_id'],
        ];

        $this->postJson('/api/votes', $payload, $headers)->assertOk();

        $this->postJson('/api/votes', $payload, $headers)
            ->assertStatus(409)
            ->assertJsonPath('message', 'You already voted on this duel.');

        $this->assertDatabaseCount('votes', 1);
    }

    public function test_store_direct_returns_expected_contract(): void
    {
        Sanctum::actingAs($this->createUser());

        $fixture = $this->createPlayerFixture();

        $response = $this->postJson('/api/votes/direct', [
            'attribute_key' => 'passing',
            'player_id' => $fixture['player_id'],
            'value' => 88,
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'vote_id',
                'attribute_id',
                'player_id',
                'value',
                'pre_rating_a',
                'post_rating_a',
                'delta_rating_a',
            ])
            ->assertJsonPath('player_id', $fixture['player_id'])
            ->assertJsonPath('value', 88);
    }

    public function test_store_direct_requires_authentication(): void
    {
        $fixture = $this->createPlayerFixture();

        $this->postJson('/api/votes/direct', [
            'attribute_key' => 'passing',
            'player_id' => $fixture['player_id'],
            'value' => 88,
        ])->assertUnauthorized();
    }

    public function test_store_direct_returns_422_for_validation_errors(): void
    {
        Sanctum::actingAs($this->createUser());

        $response = $this->postJson('/api/votes/direct', []);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors']);
    }

    public function test_store_direct_returns_409_when_vote_already_exists(): void
    {
        Sanctum::actingAs($this->createUser());

        $fixture = $this->createPlayerFixture();

        $payload = [
            'attribute_key' => 'passing',
            'player_id' => $fixture['player_id'],
            'value' => 90,
        ];

        $this->postJson('/api/votes/direct', $payload)->assertCreated();

        $this->postJson('/api/votes/direct', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Direct vote already exists for this player and attribute.');
    }

    public function test_submit_scout_report_returns_expected_contract(): void
    {
        Sanctum::actingAs($this->createUser());

        $fixture = $this->createPlayerFixture();

        $response = $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                [
                    'attribute_key' => 'passing',
                    'value' => 85,
                ],
            ],
            'skipped_attribute_ids' => [],
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'player_id',
                'created_vote_ids',
                'votes_created',
                'skipped_attribute_ids',
            ])
            ->assertJsonPath('player_id', $fixture['player_id'])
            ->assertJsonPath('votes_created', 1);
    }

    public function test_submit_scout_report_requires_authentication(): void
    {
        $fixture = $this->createPlayerFixture();

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                [
                    'attribute_key' => 'passing',
                    'value' => 85,
                ],
            ],
        ])->assertUnauthorized();
    }

    public function test_submit_scout_report_returns_422_when_payload_empty(): void
    {
        Sanctum::actingAs($this->createUser());

        $fixture = $this->createPlayerFixture();

        $response = $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [],
            'skipped_attribute_ids' => [],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.');
    }

    public function test_scout_report_attributes_returns_expected_contract(): void
    {
        Sanctum::actingAs($this->createUser());

        $fixture = $this->createPlayerFixture();

        $response = $this->getJson("/api/players/{$fixture['player_id']}/scout-report-attributes");

        $response
            ->assertOk()
            ->assertJsonStructure([
                'player_id',
                'items' => [
                    '*' => [
                        'id',
                        'key',
                        'label',
                        'group',
                        'is_skipped',
                        'description',
                    ],
                ],
                'is_completed',
                'remaining_attributes_count',
            ])
            ->assertJsonPath('player_id', $fixture['player_id']);
    }

    public function test_scout_report_attributes_requires_authentication(): void
    {
        $fixture = $this->createPlayerFixture();

        $this->getJson("/api/players/{$fixture['player_id']}/scout-report-attributes")
            ->assertUnauthorized();
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::USER,
            'influence_profile' => InfluenceProfile::USER_DEFAULT,
        ]);
    }

    /**
     * @return array{
     *     position_id: int,
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int,
     *     duel_id: int
     * }
     */
    private function createDuelFixture(): array
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $duelId = DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'created_at' => now(),
        ]);

        return [
            'position_id' => $positionId,
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'duel_id' => $duelId,
        ];
    }

    /**
     * @return array{position_id: int, player_id: int}
     */
    private function createPlayerFixture(): array
    {
        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Bruno Guimaraes',
            'slug' => 'bruno-guimaraes',
            'position_id' => $positionId,
        ]);

        DB::table('attributes')->insert([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        return [
            'position_id' => $positionId,
            'player_id' => $playerId,
        ];
    }
}
