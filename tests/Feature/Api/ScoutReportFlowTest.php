<?php

namespace Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScoutReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_scout_report_flow_persists_votes_and_skips_updates_rating_state_and_exposes_your_rating(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $passingId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $creativityId = $this->createAttribute([
            'key' => 'creativity',
            'label' => 'Creativity',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'ball_control',
            'label' => 'Ball Control',
            'group' => 'TECHNIQUE',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'work_rate',
            'label' => 'Work Rate',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'composure',
            'label' => 'Composure',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'stamina',
            'label' => 'Stamina',
            'group' => 'PHYSICAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'concentration',
            'label' => 'Concentration',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->getJson("/api/players/{$playerId}/scout-report-attributes")
            ->assertOk()
            ->assertJsonFragment(['key' => 'passing'])
            ->assertJsonFragment(['key' => 'creativity']);

        $this->postJson('/api/scout-reports', [
            'player_id' => $playerId,
            'votes' => [
                [
                    'attribute_key' => 'passing',
                    'value' => 99,
                ],
            ],
            'skipped_attribute_ids' => [$creativityId],
        ])
            ->assertCreated()
            ->assertJsonPath('player_id', $playerId)
            ->assertJsonPath('votes_created', 1)
            ->assertJsonPath('skipped_attribute_ids.0', $creativityId);

        $this->assertDatabaseHas('votes', [
            'source' => 'direct',
            'user_id' => $user->id,
            'player_a_id' => $playerId,
            'attribute_id' => $passingId,
            'value' => 99,
        ]);

        $this->assertDatabaseHas('scout_report_skips', [
            'user_id' => $user->id,
            'player_id' => $playerId,
            'attribute_id' => $creativityId,
        ]);

        $passingRatingRow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerId)
            ->where('attribute_id', $passingId)
            ->first();

        $this->assertNotNull($passingRatingRow);
        $this->assertSame(1, (int) $passingRatingRow->votes_count);
        $this->assertEquals(1.0, (float) $passingRatingRow->rating_weight_sum);
        $this->assertEquals(1.0, (float) $passingRatingRow->confidence_weight_sum);
        $this->assertEquals(1.0, (float) $passingRatingRow->confidence);

        $this->postJson('/api/scout-reports', [
            'player_id' => $playerId,
            'votes' => [
                [
                    'attribute_key' => 'creativity',
                    'value' => 80,
                ],
            ],
            'skipped_attribute_ids' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('player_id', $playerId)
            ->assertJsonPath('votes_created', 1);

        $this->assertDatabaseHas('votes', [
            'source' => 'direct',
            'user_id' => $user->id,
            'player_a_id' => $playerId,
            'attribute_id' => $creativityId,
            'value' => 80,
        ]);

        $this->assertDatabaseMissing('scout_report_skips', [
            'user_id' => $user->id,
            'player_id' => $playerId,
            'attribute_id' => $creativityId,
        ]);

        $profileResponse = $this->getJson("/api/players/{$playerId}")
            ->assertOk();

        $attributesByKey = collect($profileResponse->json('attributes'))
            ->keyBy('key');

        $this->assertSame(99, $attributesByKey->get('passing')['your_rating']);
        $this->assertSame(80, $attributesByKey->get('creativity')['your_rating']);

        $remainingKeys = collect(
            $this->getJson("/api/players/{$playerId}/scout-report-attributes")
                ->assertOk()
                ->json('items')
        )
            ->pluck('key')
            ->all();

        $this->assertNotContains('passing', $remainingKeys);
        $this->assertNotContains('creativity', $remainingKeys);
    }

    private function createPosition(array $data): int
    {
        return (int) DB::table('positions')->insertGetId([
            'short_label' => $data['short_label'],
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPlayer(array $data): int
    {
        return (int) DB::table('players')->insertGetId([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'position_id' => $data['position_id'],
            'club_id' => null,
            'country_id' => null,
            'number' => null,
            'date_of_birth' => null,
        ]);
    }

    private function createAttribute(array $data): int
    {
        return (int) DB::table('attributes')->insertGetId([
            'key' => $data['key'],
            'label' => $data['label'],
            'group' => $data['group'],
            'scope' => $data['scope'],
        ]);
    }

    public function test_direct_vote_cannot_be_submitted_twice_for_same_player_and_attribute(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $this->postJson('/api/votes/direct', [
            'attribute_key' => 'passing',
            'player_id' => $playerId,
            'value' => 90,
        ])->assertCreated();

        $this->postJson('/api/votes/direct', [
            'attribute_key' => 'passing',
            'player_id' => $playerId,
            'value' => 95,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Direct vote already exists for this player and attribute.');

        $this->assertDatabaseCount('votes', 1);
    }

    public function test_scout_report_attributes_exclude_voted_and_push_skipped_out_of_top_six(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Bruno Guimaraes',
            'slug' => 'bruno-guimaraes',
            'position_id' => $positionId,
        ]);

        $passingId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $creativityId = $this->createAttribute([
            'key' => 'creativity',
            'label' => 'Creativity',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'ball_control',
            'label' => 'Ball Control',
            'group' => 'TECHNIQUE',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'work_rate',
            'label' => 'Work Rate',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'composure',
            'label' => 'Composure',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'stamina',
            'label' => 'Stamina',
            'group' => 'PHYSICAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'concentration',
            'label' => 'Concentration',
            'group' => 'MENTAL',
            'scope' => 'both',
        ]);

        $this->createAttribute([
            'key' => 'dribbling',
            'label' => 'Dribbling',
            'group' => 'TECHNIQUE',
            'scope' => 'both',
        ]);

        $this->postJson('/api/votes/direct', [
            'attribute_key' => 'passing',
            'player_id' => $playerId,
            'value' => 91,
        ])->assertCreated();

        $this->postJson('/api/scout-reports', [
            'player_id' => $playerId,
            'votes' => [],
            'skipped_attribute_ids' => [$creativityId],
        ])->assertCreated();

        $response = $this->getJson("/api/players/{$playerId}/scout-report-attributes")
            ->assertOk();

        $items = collect($response->json('items'));
        $keys = $items->pluck('key')->all();

        $this->assertCount(6, $keys);
        $this->assertNotContains('passing', $keys);
        $this->assertNotContains('creativity', $keys);
        $this->assertContains('ball_control', $keys);
        $this->assertContains('work_rate', $keys);
    }

    public function test_direct_vote_persists_pre_and_post_rating_snapshots(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'creativity',
            'label' => 'Creativity',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $playerId,
            'attribute_id' => $attributeId,
            'rating' => 80.0,
            'rating_weight_sum' => 1.0,
            'confidence_weight_sum' => 1.0,
            'confidence' => 1.0,
            'votes_count' => 1,
            'last_vote_at' => now(),
        ]);

        $response = $this->postJson('/api/votes/direct', [
            'attribute_key' => 'creativity',
            'player_id' => $playerId,
            'value' => 77,
        ])->assertCreated();

        $this->assertEquals(80.0, (float) $response->json('pre_rating_a'));
        $this->assertEquals(78.5, (float) $response->json('post_rating_a'));
        $this->assertEquals(-1.5, (float) $response->json('delta_rating_a'));

        $voteId = (int) $response->json('vote_id');

        $this->assertDatabaseHas('votes', [
            'id' => $voteId,
            'source' => 'direct',
            'user_id' => $user->id,
            'player_a_id' => $playerId,
            'attribute_id' => $attributeId,
            'value' => 77,
            'pre_rating_a' => 80.0,
            'post_rating_a' => 78.5,
        ]);
    }
}
