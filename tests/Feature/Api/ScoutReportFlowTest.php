<?php

namespace Tests\Api\Feature;

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
}
