<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScoutReportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_with_new_votes_creates_submission_and_links_votes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutReportFixture();

        $response = $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => $fixture['passing_key'], 'value' => 88],
                ['attribute_key' => $fixture['creativity_key'], 'value' => 77],
            ],
            'skipped_attribute_ids' => [],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('votes_created', 2)
            ->assertJsonPath('player_id', $fixture['player_id']);

        $submissionId = $response->json('submission_id');
        $this->assertNotEmpty($submissionId);

        $this->assertDatabaseHas('scout_report_submissions', [
            'id' => $submissionId,
            'user_id' => $user->id,
            'player_id' => $fixture['player_id'],
            'ratings_count' => 2,
        ]);

        $submission = DB::table('scout_report_submissions')->where('id', $submissionId)->first();
        $this->assertNotNull($submission->pre_overall);
        $this->assertNotNull($submission->post_overall);

        $linkedVotes = DB::table('votes')
            ->where('scout_report_submission_id', $submissionId)
            ->get();

        $this->assertCount(2, $linkedVotes);
        $this->assertTrue($linkedVotes->every(fn ($vote) => $vote->source === 'direct'));
    }

    public function test_skip_only_submit_does_not_create_submission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutReportFixture();

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [],
            'skipped_attribute_ids' => [$fixture['creativity_id']],
        ])
            ->assertCreated()
            ->assertJsonPath('votes_created', 0)
            ->assertJsonPath('submission_id', null);

        $this->assertDatabaseCount('scout_report_submissions', 0);
        $this->assertDatabaseHas('scout_report_skips', [
            'user_id' => $user->id,
            'player_id' => $fixture['player_id'],
            'attribute_id' => $fixture['creativity_id'],
        ]);
    }

    public function test_duplicate_direct_vote_in_submit_rolls_back_entire_submission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutReportFixture();

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => $fixture['passing_key'], 'value' => 88],
            ],
            'skipped_attribute_ids' => [],
        ])->assertCreated();

        $this->assertDatabaseCount('scout_report_submissions', 1);

        $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => $fixture['passing_key'], 'value' => 90],
                ['attribute_key' => $fixture['creativity_key'], 'value' => 77],
            ],
            'skipped_attribute_ids' => [],
        ])->assertStatus(409);

        $this->assertDatabaseCount('scout_report_submissions', 1);

        $this->assertDatabaseMissing('votes', [
            'user_id' => $user->id,
            'player_a_id' => $fixture['player_id'],
            'attribute_id' => $fixture['creativity_id'],
        ]);

        $this->assertDatabaseHas('votes', [
            'user_id' => $user->id,
            'player_a_id' => $fixture['player_id'],
            'attribute_id' => $fixture['passing_id'],
            'value' => 88,
        ]);
    }

    public function test_ratings_count_reflects_created_rows_not_payload_size(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutReportFixture();

        $submissionId = $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => $fixture['passing_key'], 'value' => 81],
            ],
            'skipped_attribute_ids' => [],
        ])
            ->assertCreated()
            ->json('submission_id');

        $this->assertDatabaseHas('scout_report_submissions', [
            'id' => $submissionId,
            'ratings_count' => 1,
        ]);
    }

    public function test_post_overall_reflects_rating_changes_after_submit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createScoutReportFixture();

        $submissionId = $this->postJson('/api/scout-reports', [
            'player_id' => $fixture['player_id'],
            'votes' => [
                ['attribute_key' => $fixture['passing_key'], 'value' => 99],
            ],
            'skipped_attribute_ids' => [],
        ])
            ->assertCreated()
            ->json('submission_id');

        $submission = DB::table('scout_report_submissions')->where('id', $submissionId)->first();

        $this->assertNotNull($submission);
        $this->assertNotNull($submission->pre_overall);
        $this->assertNotNull($submission->post_overall);
        $this->assertNotEquals((float) $submission->pre_overall, (float) $submission->post_overall);
    }

    /**
     * @return array{
     *     player_id: int,
     *     passing_id: int,
     *     creativity_id: int,
     *     passing_key: string,
     *     creativity_key: string
     * }
     */
    private function createScoutReportFixture(): array
    {
        $unique = uniqid();

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm-'.$unique,
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerId = DB::table('players')->insertGetId([
            'name' => 'Test Player '.$unique,
            'slug' => 'test-player-'.$unique,
            'position_id' => $positionId,
        ]);

        $passingId = DB::table('attributes')->where('key', 'passing')->value('id');
        if (! $passingId) {
            $passingId = DB::table('attributes')->insertGetId([
                'key' => 'passing',
                'label' => 'Passing',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);
        }

        $creativityId = DB::table('attributes')->where('key', 'creativity')->value('id');
        if (! $creativityId) {
            $creativityId = DB::table('attributes')->insertGetId([
                'key' => 'creativity',
                'label' => 'Creativity',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);
        }

        return [
            'player_id' => $playerId,
            'passing_id' => (int) $passingId,
            'creativity_id' => (int) $creativityId,
            'passing_key' => 'passing',
            'creativity_key' => 'creativity',
        ];
    }
}
