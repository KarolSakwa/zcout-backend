<?php

namespace Tests\Feature\Api;

use App\Enums\InfluenceProfile;
use App\Enums\UserRole;
use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Models\User;
use App\Services\Ranking\AttributeRankingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScoutingProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andReturn([
                    'rank' => null,
                    'is_top_ten' => false,
                ]);
        });

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");
    }

    public function test_progress_requires_voter_identity(): void
    {
        $this->getJson('/api/scouting/progress')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Missing voter identity. Provide X-Zcout-Anon or authenticate.');
    }

    public function test_progress_returns_zero_for_anon_with_no_votes(): void
    {
        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => 'scouting-progress-anon-empty',
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 0)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', false)
            ->assertJsonPath('scouting_progress.progress_target', 25)
            ->assertJsonPath('scouting_progress.stage_progress', 0)
            ->assertJsonPath('scouting_progress.stage_target', 25)
            ->assertJsonPath('scouting_progress.next_unlock', 'my_scouting');
    }

    public function test_duel_vote_increases_contributions_by_one(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-duel-vote';

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixture),
            ['X-Zcout-Anon' => $anonId],
        )->assertOk();

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_duel_skip_does_not_increase_contributions(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-duel-skip';

        $this->postJson('/api/duels/skip', [
            'duel_id' => $fixture['duel_id'],
        ], [
            'X-Zcout-Anon' => $anonId,
        ])->assertOk();

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 0);
    }

    public function test_duplicate_duel_vote_does_not_increase_contributions(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-duel-duplicate';
        $headers = ['X-Zcout-Anon' => $anonId];
        $payload = $this->duelVotePayload($fixture);

        $this->postJson('/api/votes', $payload, $headers)->assertOk();
        $this->postJson('/api/votes', $payload, $headers)->assertStatus(409);

        $this->getJson('/api/scouting/progress', $headers)
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_anon_progress_counts_only_unclaimed_votes(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-anon-unclaimed';
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->insertDuelVoteRow($fixture, $voterHash, null);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_logged_user_progress_counts_user_votes(): void
    {
        $user = $this->createUser();
        $fixture = $this->createDuelFixture();
        $userLockHash = hash_hmac(
            'sha256',
            'user:'.$user->id,
            (string) config('app.key'),
        );

        $this->insertDuelVoteRow($fixture, $userLockHash, $user->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/scouting/progress')
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_logged_user_with_anon_header_does_not_double_count_claimed_vote(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-logged-anon';
        $user = $this->createUser();
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixture),
            ['X-Zcout-Anon' => $anonId],
        )->assertOk();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], [
            'X-Zcout-Anon' => $anonId,
        ])->assertOk();

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);

        $this->assertDatabaseHas('votes', [
            'duel_id' => $fixture['duel_id'],
            'voter_hash' => $voterHash,
            'user_id' => $user->id,
        ]);
    }

    public function test_claim_does_not_double_count_contributions(): void
    {
        $fixtureA = $this->createDuelFixture();
        $fixtureB = $this->createDuelFixture();
        $anonId = 'scouting-progress-claim-no-double';
        $user = $this->createUser();
        $headers = ['X-Zcout-Anon' => $anonId];

        $this->postJson('/api/votes', $this->duelVotePayload($fixtureA), $headers)->assertOk();
        $this->postJson('/api/votes', $this->duelVotePayload($fixtureB), $headers)->assertOk();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], $headers)
            ->assertOk()
            ->assertJsonPath('claimed', 2);

        $this->getJson('/api/scouting/progress', $headers)
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 2);
    }

    public function test_below_twenty_five_is_locked(): void
    {
        $anonId = 'scouting-progress-below-25';
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->seedDuelVoteRows($voterHash, null, 24);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 24)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', false)
            ->assertJsonPath('scouting_progress.progress_target', 25)
            ->assertJsonPath('scouting_progress.stage_progress', 24)
            ->assertJsonPath('scouting_progress.stage_target', 25)
            ->assertJsonPath('scouting_progress.next_unlock', 'my_scouting');
    }

    public function test_at_twenty_five_unlocks_my_scouting(): void
    {
        $anonId = 'scouting-progress-at-25';
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->seedDuelVoteRows($voterHash, null, 25);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 25)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.progress_target', 100)
            ->assertJsonPath('scouting_progress.stage_progress', 0)
            ->assertJsonPath('scouting_progress.stage_target', 100)
            ->assertJsonPath('scouting_progress.next_unlock', 'your_impact');
    }

    public function test_config_override_unlocks_my_scouting_at_custom_threshold(): void
    {
        config([
            'scouting.my_scouting_unlock' => 2,
            'scouting.your_impact_unlock' => 102,
        ]);

        $anonId = 'scouting-progress-override-2';
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->seedDuelVoteRows($voterHash, null, 2);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 2)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.progress_target', 100)
            ->assertJsonPath('scouting_progress.stage_progress', 0)
            ->assertJsonPath('scouting_progress.stage_target', 100)
            ->assertJsonPath('scouting_progress.next_unlock', 'your_impact');
    }

    public function test_default_config_keeps_twenty_five_and_one_twenty_five_unlock_thresholds(): void
    {
        $this->assertSame(25, (int) config('scouting.my_scouting_unlock'));
        $this->assertSame(125, (int) config('scouting.your_impact_unlock'));
    }

    public function test_stage_two_progress_counts_from_my_scouting_unlock(): void
    {
        $anonId = 'scouting-progress-stage-two';
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->seedDuelVoteRows($voterHash, null, 75);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 75)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.stage_progress', 50)
            ->assertJsonPath('scouting_progress.stage_target', 100)
            ->assertJsonPath('scouting_progress.next_unlock', 'your_impact');
    }

    public function test_contributions_at_or_above_one_twenty_five_cap_stage_progress_at_one_hundred(): void
    {
        $user = $this->createUser();
        $userLockHash = hash_hmac(
            'sha256',
            'user:'.$user->id,
            (string) config('app.key'),
        );

        $this->seedDuelVoteRows($userLockHash, $user->id, 134);

        Sanctum::actingAs($user);

        $this->getJson('/api/scouting/progress')
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 134)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.progress_target', 100)
            ->assertJsonPath('scouting_progress.stage_progress', 100)
            ->assertJsonPath('scouting_progress.stage_target', 100)
            ->assertJsonPath('scouting_progress.next_unlock', 'your_impact');
    }

    public function test_direct_votes_count_toward_contributions_for_logged_user(): void
    {
        $user = $this->createUser();
        $fixture = $this->createPlayerFixture();

        $this->insertDirectVoteRow($user->id, $fixture['player_id'], $fixture['attribute_id']);

        Sanctum::actingAs($user);

        $this->getJson('/api/scouting/progress')
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_logged_user_unclaimed_anon_votes_are_included_with_anon_header(): void
    {
        $user = $this->createUser();
        $fixture = $this->createDuelFixture();
        $anonId = 'scouting-progress-unclaimed-plus-user';
        $anonHash = hash_hmac('sha256', $anonId, (string) config('app.key'));
        $userLockHash = hash_hmac(
            'sha256',
            'user:'.$user->id,
            (string) config('app.key'),
        );

        $this->insertDuelVoteRow($fixture, $anonHash, null);
        $this->insertDuelVoteRow($this->createDuelFixture(), $userLockHash, $user->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/scouting/progress', [
            'X-Zcout-Anon' => $anonId,
        ])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 2);
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
        $unique = uniqid();

        $positionId = DB::table('positions')->insertGetId([
            'short_label' => 'CM',
            'key' => 'cm-'.$unique,
            'label' => 'Central Midfielder',
            'group' => 'MIDFIELD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Martin Odegaard',
            'slug' => 'martin-odegaard-'.uniqid(),
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Declan Rice',
            'slug' => 'declan-rice-'.uniqid(),
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')
            ->where('key', 'passing')
            ->value('id');

        if (! $attributeId) {
            $attributeId = DB::table('attributes')->insertGetId([
                'key' => 'passing',
                'label' => 'Passing',
                'group' => 'PASSING',
                'scope' => 'both',
            ]);
        }

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
     * @param  array{
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int,
     *     duel_id: int
     * }  $fixture
     * @return array<string, mixed>
     */
    private function duelVotePayload(array $fixture): array
    {
        return [
            'attribute_key' => 'passing',
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'duel_id' => $fixture['duel_id'],
        ];
    }

    /**
     * @param  array{
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int,
     *     duel_id: int
     * }  $fixture
     */
    private function insertDuelVoteRow(array $fixture, string $voterHash, ?int $userId): void
    {
        DB::table('votes')->insert([
            'source' => 'duel',
            'attribute_id' => $fixture['attribute_id'],
            'duel_id' => $fixture['duel_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'user_id' => $userId,
            'voter_hash' => $voterHash,
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'pre_rating_b' => 78.000,
            'post_rating_a' => 80.030,
            'post_rating_b' => 77.970,
            'created_at' => now(),
        ]);
    }

    private function seedDuelVoteRows(string $voterHash, ?int $userId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $fixture = $this->createDuelFixture();
            $this->insertDuelVoteRow($fixture, $voterHash, $userId);
        }
    }

    /**
     * @return array{player_id: int, attribute_id: int}
     */
    private function createPlayerFixture(): array
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
            'name' => 'Test Player',
            'slug' => 'test-player-'.uniqid(),
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace-'.uniqid(),
            'label' => 'Pace',
            'group' => 'PACE',
            'scope' => 'both',
        ]);

        return [
            'player_id' => $playerId,
            'attribute_id' => $attributeId,
        ];
    }

    private function insertDirectVoteRow(int $userId, int $playerId, int $attributeId): void
    {
        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $attributeId,
            'player_a_id' => $playerId,
            'user_id' => $userId,
            'value' => 85,
            'weight_applied' => 1.0,
            'confidence_weight_applied' => 0.5,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'post_rating_a' => 80.500,
            'created_at' => now(),
        ]);
    }
}
