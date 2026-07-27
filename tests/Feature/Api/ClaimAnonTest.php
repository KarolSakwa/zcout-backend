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

class ClaimAnonTest extends TestCase
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
    }

    public function test_claim_standard_anonymous_duel_vote_sets_user_id(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'claim-anon-standard-1';
        $user = $this->createUser();

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixture),
            ['X-Zcout-Anon' => $anonId],
        )->assertOk();

        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/claim-anon', [], [
            'X-Zcout-Anon' => $anonId,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('claimed', 1);

        $this->assertDatabaseHas('votes', [
            'duel_id' => $fixture['duel_id'],
            'voter_hash' => $voterHash,
            'user_id' => $user->id,
        ]);
    }

    public function test_claim_legacy_identifiers_finds_votes_for_both_hmacs(): void
    {
        $fixtureA = $this->createDuelFixture();
        $fixtureB = $this->createDuelFixture();
        $primaryAnonId = 'claim-anon-primary';
        $legacyAnonId = 'claim-anon-legacy';
        $user = $this->createUser();

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixtureA),
            ['X-Zcout-Anon' => $primaryAnonId],
        )->assertOk();

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixtureB),
            ['X-Zcout-Anon' => $legacyAnonId],
        )->assertOk();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/claim-anon', [], [
            'X-Zcout-Anon' => $primaryAnonId,
            'X-Zcout-Anon-Legacy' => $legacyAnonId,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('claimed', 2);

        $this->assertDatabaseHas('votes', [
            'duel_id' => $fixtureA['duel_id'],
            'voter_hash' => hash_hmac('sha256', $primaryAnonId, (string) config('app.key')),
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('votes', [
            'duel_id' => $fixtureB['duel_id'],
            'voter_hash' => hash_hmac('sha256', $legacyAnonId, (string) config('app.key')),
            'user_id' => $user->id,
        ]);
    }

    public function test_claim_does_not_change_voter_hash_or_weight_applied(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'claim-anon-immutable-fields';
        $user = $this->createUser();
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        $this->postJson(
            '/api/votes',
            $this->duelVotePayload($fixture),
            ['X-Zcout-Anon' => $anonId],
        )->assertOk();

        $before = DB::table('votes')
            ->where('duel_id', $fixture['duel_id'])
            ->first();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], [
            'X-Zcout-Anon' => $anonId,
        ])->assertOk();

        $after = DB::table('votes')
            ->where('duel_id', $fixture['duel_id'])
            ->first();

        $this->assertSame($before->voter_hash, $after->voter_hash);
        $this->assertSame($before->weight_applied, $after->weight_applied);
        $this->assertSame((int) $user->id, (int) $after->user_id);
    }

    public function test_anonymous_vote_then_logged_vote_with_same_anon_header_returns_409(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");

        $fixture = $this->createDuelFixture();
        $anonId = 'claim-anon-dedup-after-login';
        $user = $this->createUser();
        $payload = $this->duelVotePayload($fixture);
        $headers = ['X-Zcout-Anon' => $anonId];

        $this->postJson('/api/votes', $payload, $headers)->assertOk();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], $headers)->assertOk();

        $this->postJson('/api/votes', $payload, $headers)
            ->assertStatus(409)
            ->assertJsonPath('message', 'You already voted on this duel.');

        $this->assertDatabaseCount('votes', 1);
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
        $positionId = DB::table('positions')
            ->where('key', 'cm')
            ->value('id');

        if (! $positionId) {
            $positionId = DB::table('positions')->insertGetId([
                'short_label' => 'CM',
                'key' => 'cm',
                'label' => 'Central Midfielder',
                'group' => 'MIDFIELD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
}
