<?php

namespace Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RebuildRatingsFromBaselineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rebuilds_ratings_from_baseline_and_replays_duel_and_direct_votes(): void
    {
        $user = User::factory()->create();

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerAId = $this->createPlayer([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        DB::table('player_attribute_ratings')->insert([
            'player_id' => $playerAId,
            'attribute_id' => $attributeId,
            'rating' => 12.345,
            'votes_count' => 99,
            'rating_weight_sum' => 99,
            'confidence_weight_sum' => 99,
            'confidence' => 99,
            'last_vote_at' => now(),
        ]);

        $duelId = (int) DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ]);

        DB::table('votes')->insert([
            'source' => 'duel',
            'attribute_id' => $attributeId,
            'duel_id' => $duelId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'winner_id' => $playerAId,
            'user_id' => null,
            'voter_hash' => 'anon-rebuild-test',
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
            'weight_version' => 1,
            'reputation_at_vote' => null,
            'risk_score_at_vote' => null,
            'value' => null,
            'pre_rating_a' => null,
            'pre_rating_b' => null,
            'post_rating_a' => null,
            'post_rating_b' => null,
            'created_at' => '2026-04-01 10:00:00',
        ]);

        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $attributeId,
            'duel_id' => null,
            'player_a_id' => $playerAId,
            'player_b_id' => null,
            'winner_id' => null,
            'user_id' => $user->id,
            'voter_hash' => null,
            'weight_applied' => 1.0,
            'confidence_weight_applied' => 1.0,
            'weight_version' => 1,
            'reputation_at_vote' => null,
            'risk_score_at_vote' => null,
            'value' => 99,
            'pre_rating_a' => null,
            'pre_rating_b' => null,
            'post_rating_a' => null,
            'post_rating_b' => null,
            'created_at' => '2026-04-01 11:00:00',
        ]);

        $baselinePath = storage_path('app/test-baseline-rebuild.json');

        file_put_contents($baselinePath, json_encode([
            'version' => 1,
            'updated_at' => '2026-04-01T09:00:00Z',
            'players' => [
                (string) $playerAId => [
                    'name' => 'Martin Odegaard',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 80,
                    ],
                    'review_attributes' => [],
                ],
                (string) $playerBId => [
                    'name' => 'Declan Rice',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 70,
                    ],
                    'review_attributes' => [],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $this->artisan('zcout:rebuild-ratings-from-baseline', [
                '--baseline-json' => $baselinePath,
                '--reset' => '1',
                '--progress-every' => '1',
            ])->assertExitCode(0);
        } finally {
            if (is_file($baselinePath)) {
                unlink($baselinePath);
            }
        }

        $this->assertSame(2, DB::table('player_attribute_ratings')->count());

        $playerARow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerAId)
            ->where('attribute_id', $attributeId)
            ->first();

        $playerBRow = DB::table('player_attribute_ratings')
            ->where('player_id', $playerBId)
            ->where('attribute_id', $attributeId)
            ->first();

        $this->assertNotNull($playerARow);
        $this->assertNotNull($playerBRow);

        $this->assertEqualsWithDelta(92.734, (float) $playerARow->rating, 0.001);
        $this->assertEqualsWithDelta(69.799, (float) $playerBRow->rating, 0.001);

        $this->assertSame(2, (int) $playerARow->votes_count);
        $this->assertSame(1, (int) $playerBRow->votes_count);

        $this->assertEquals(1.5, (float) $playerARow->rating_weight_sum);
        $this->assertEquals(0.5, (float) $playerBRow->rating_weight_sum);

        $this->assertEquals(1.1, (float) $playerARow->confidence_weight_sum);
        $this->assertEquals(0.1, (float) $playerBRow->confidence_weight_sum);

        $this->assertEquals(1.1, (float) $playerARow->confidence);
        $this->assertEquals(0.1, (float) $playerBRow->confidence);

        $duelVote = DB::table('votes')
            ->where('source', 'duel')
            ->first();

        $directVote = DB::table('votes')
            ->where('source', 'direct')
            ->first();

        $this->assertNotNull($duelVote);
        $this->assertNotNull($directVote);

        $this->assertEquals('80.000', (string) $duelVote->pre_rating_a);
        $this->assertEquals('70.000', (string) $duelVote->pre_rating_b);
        $this->assertEquals('80.201', (string) $duelVote->post_rating_a);
        $this->assertEquals('69.799', (string) $duelVote->post_rating_b);

        $this->assertEquals('80.201', (string) $directVote->pre_rating_a);
        $this->assertEquals('92.734', (string) $directVote->post_rating_a);
        $this->assertNull($directVote->pre_rating_b);
        $this->assertNull($directVote->post_rating_b);
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

    public function test_it_is_deterministic_when_run_twice_on_same_baseline_and_votes(): void
    {
        $user = User::factory()->create();

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerAId = $this->createPlayer([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $duelId = (int) DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ]);

        DB::table('votes')->insert([
            [
                'source' => 'duel',
                'attribute_id' => $attributeId,
                'duel_id' => $duelId,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
                'user_id' => null,
                'voter_hash' => 'anon-deterministic-test',
                'weight_applied' => 0.5,
                'confidence_weight_applied' => 0.1,
                'weight_version' => 1,
                'reputation_at_vote' => null,
                'risk_score_at_vote' => null,
                'value' => null,
                'pre_rating_a' => null,
                'pre_rating_b' => null,
                'post_rating_a' => null,
                'post_rating_b' => null,
                'created_at' => '2026-04-01 10:00:00',
            ],
            [
                'source' => 'direct',
                'attribute_id' => $attributeId,
                'duel_id' => null,
                'player_a_id' => $playerAId,
                'player_b_id' => null,
                'winner_id' => null,
                'user_id' => $user->id,
                'voter_hash' => null,
                'weight_applied' => 1.0,
                'confidence_weight_applied' => 1.0,
                'weight_version' => 1,
                'reputation_at_vote' => null,
                'risk_score_at_vote' => null,
                'value' => 99,
                'pre_rating_a' => null,
                'pre_rating_b' => null,
                'post_rating_a' => null,
                'post_rating_b' => null,
                'created_at' => '2026-04-01 11:00:00',
            ],
        ]);

        $baselinePath = storage_path('app/test-baseline-rebuild-deterministic.json');

        file_put_contents($baselinePath, json_encode([
            'version' => 1,
            'updated_at' => '2026-04-01T09:00:00Z',
            'players' => [
                (string) $playerAId => [
                    'name' => 'Martin Odegaard',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 80,
                    ],
                    'review_attributes' => [],
                ],
                (string) $playerBId => [
                    'name' => 'Declan Rice',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 70,
                    ],
                    'review_attributes' => [],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $this->artisan('zcout:rebuild-ratings-from-baseline', [
                '--baseline-json' => $baselinePath,
                '--reset' => '1',
                '--progress-every' => '1000',
            ])->assertExitCode(0);

            $firstRatings = DB::table('player_attribute_ratings')
                ->orderBy('player_id')
                ->orderBy('attribute_id')
                ->get([
                    'player_id',
                    'attribute_id',
                    'rating',
                    'votes_count',
                    'rating_weight_sum',
                    'confidence_weight_sum',
                    'confidence',
                    'last_vote_at',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            $firstVotes = DB::table('votes')
                ->orderBy('id')
                ->get([
                    'id',
                    'pre_rating_a',
                    'pre_rating_b',
                    'post_rating_a',
                    'post_rating_b',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            $this->artisan('zcout:rebuild-ratings-from-baseline', [
                '--baseline-json' => $baselinePath,
                '--reset' => '1',
                '--progress-every' => '1000',
            ])->assertExitCode(0);

            $secondRatings = DB::table('player_attribute_ratings')
                ->orderBy('player_id')
                ->orderBy('attribute_id')
                ->get([
                    'player_id',
                    'attribute_id',
                    'rating',
                    'votes_count',
                    'rating_weight_sum',
                    'confidence_weight_sum',
                    'confidence',
                    'last_vote_at',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            $secondVotes = DB::table('votes')
                ->orderBy('id')
                ->get([
                    'id',
                    'pre_rating_a',
                    'pre_rating_b',
                    'post_rating_a',
                    'post_rating_b',
                ])
                ->map(fn ($row) => (array) $row)
                ->all();

            $this->assertSame($firstRatings, $secondRatings);
            $this->assertSame($firstVotes, $secondVotes);
        } finally {
            if (is_file($baselinePath)) {
                unlink($baselinePath);
            }
        }
    }

    public function test_it_changes_final_state_when_baseline_changes(): void
    {
        $user = User::factory()->create();

        $positionId = $this->createPosition([
            'short_label' => 'CM',
            'key' => 'cm',
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
        ]);

        $playerAId = $this->createPlayer([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard',
            'position_id' => $positionId,
        ]);

        $playerBId = $this->createPlayer([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice',
            'position_id' => $positionId,
        ]);

        $attributeId = $this->createAttribute([
            'key' => 'passing',
            'label' => 'Passing',
            'group' => 'PASSING',
            'scope' => 'both',
        ]);

        $duelId = (int) DB::table('duels')->insertGetId([
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ]);

        DB::table('votes')->insert([
            [
                'source' => 'duel',
                'attribute_id' => $attributeId,
                'duel_id' => $duelId,
                'player_a_id' => $playerAId,
                'player_b_id' => $playerBId,
                'winner_id' => $playerAId,
                'user_id' => null,
                'voter_hash' => 'anon-baseline-change-test',
                'weight_applied' => 0.5,
                'confidence_weight_applied' => 0.1,
                'weight_version' => 1,
                'reputation_at_vote' => null,
                'risk_score_at_vote' => null,
                'value' => null,
                'pre_rating_a' => null,
                'pre_rating_b' => null,
                'post_rating_a' => null,
                'post_rating_b' => null,
                'created_at' => '2026-04-01 10:00:00',
            ],
            [
                'source' => 'direct',
                'attribute_id' => $attributeId,
                'duel_id' => null,
                'player_a_id' => $playerAId,
                'player_b_id' => null,
                'winner_id' => null,
                'user_id' => $user->id,
                'voter_hash' => null,
                'weight_applied' => 1.0,
                'confidence_weight_applied' => 1.0,
                'weight_version' => 1,
                'reputation_at_vote' => null,
                'risk_score_at_vote' => null,
                'value' => 99,
                'pre_rating_a' => null,
                'pre_rating_b' => null,
                'post_rating_a' => null,
                'post_rating_b' => null,
                'created_at' => '2026-04-01 11:00:00',
            ],
        ]);

        $baselineAPath = storage_path('app/test-baseline-a.json');
        $baselineBPath = storage_path('app/test-baseline-b.json');

        file_put_contents($baselineAPath, json_encode([
            'version' => 1,
            'updated_at' => '2026-04-01T09:00:00Z',
            'players' => [
                (string) $playerAId => [
                    'name' => 'Martin Odegaard',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 80,
                    ],
                    'review_attributes' => [],
                ],
                (string) $playerBId => [
                    'name' => 'Declan Rice',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 70,
                    ],
                    'review_attributes' => [],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($baselineBPath, json_encode([
            'version' => 1,
            'updated_at' => '2026-04-01T09:00:00Z',
            'players' => [
                (string) $playerAId => [
                    'name' => 'Martin Odegaard',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 60,
                    ],
                    'review_attributes' => [],
                ],
                (string) $playerBId => [
                    'name' => 'Declan Rice',
                    'position' => 'CM',
                    'club' => 'Arsenal',
                    'attributes' => [
                        'passing' => 90,
                    ],
                    'review_attributes' => [],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $this->artisan('zcout:rebuild-ratings-from-baseline', [
                '--baseline-json' => $baselineAPath,
                '--reset' => '1',
                '--progress-every' => '1000',
            ])->assertExitCode(0);

            $aPlayerA = DB::table('player_attribute_ratings')
                ->where('player_id', $playerAId)
                ->where('attribute_id', $attributeId)
                ->value('rating');

            $aPlayerB = DB::table('player_attribute_ratings')
                ->where('player_id', $playerBId)
                ->where('attribute_id', $attributeId)
                ->value('rating');

            $this->artisan('zcout:rebuild-ratings-from-baseline', [
                '--baseline-json' => $baselineBPath,
                '--reset' => '1',
                '--progress-every' => '1000',
            ])->assertExitCode(0);

            $bPlayerA = DB::table('player_attribute_ratings')
                ->where('player_id', $playerAId)
                ->where('attribute_id', $attributeId)
                ->value('rating');

            $bPlayerB = DB::table('player_attribute_ratings')
                ->where('player_id', $playerBId)
                ->where('attribute_id', $attributeId)
                ->value('rating');

            $this->assertNotSame((string) $aPlayerA, (string) $bPlayerA);
            $this->assertNotSame((string) $aPlayerB, (string) $bPlayerB);
        } finally {
            if (is_file($baselineAPath)) {
                unlink($baselineAPath);
            }

            if (is_file($baselineBPath)) {
                unlink($baselineBPath);
            }
        }
    }
}
