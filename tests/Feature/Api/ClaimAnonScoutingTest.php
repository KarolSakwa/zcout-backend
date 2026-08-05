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

class ClaimAnonScoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andReturn(['rank' => null, 'is_top_ten' => false]);
        });

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS votes_unique_duel_voterhash
            ON votes (duel_id, voter_hash)
            WHERE source = 'duel'
        ");
    }

    public function test_claim_response_includes_scouting_progress(): void
    {
        $fixture = $this->createDuelFixture();
        $anonId = 'claim-scouting-progress';
        $user = $this->createUser();

        $this->postJson('/api/votes', $this->duelVotePayload($fixture), [
            'X-Zcout-Anon' => $anonId,
        ])->assertOk();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], ['X-Zcout-Anon' => $anonId])
            ->assertOk()
            ->assertJsonPath('claimed', 1)
            ->assertJsonStructure([
                'claimed',
                'scouting_progress' => [
                    'contributions',
                    'my_scouting_unlocked',
                    'progress_target',
                    'stage_progress',
                    'stage_target',
                    'next_unlock',
                ],
            ])
            ->assertJsonPath('scouting_progress.contributions', 1);
    }

    public function test_claim_does_not_double_count_contributions(): void
    {
        $fixtureA = $this->createDuelFixture();
        $fixtureB = $this->createDuelFixture();
        $anonId = 'claim-scouting-no-double';
        $user = $this->createUser();
        $headers = ['X-Zcout-Anon' => $anonId];

        $this->postJson('/api/votes', $this->duelVotePayload($fixtureA), $headers)->assertOk();
        $this->postJson('/api/votes', $this->duelVotePayload($fixtureB), $headers)->assertOk();

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], $headers)
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 2);
    }

    public function test_claim_can_unlock_my_scouting_and_expose_dashboard(): void
    {
        $anonId = 'claim-scouting-unlock-25';
        $user = $this->createUser();
        $headers = ['X-Zcout-Anon' => $anonId];

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/votes', $this->duelVotePayload($this->createDuelFixture()), $headers)->assertOk();
        }

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/claim-anon', [], $headers)
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 25)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true);

        $this->getJson('/api/my-scouting', $headers)
            ->assertOk()
            ->assertJsonPath('stats.duels', 25);

        $recent = $this->getJson('/api/my-scouting', $headers)->json('recent_contributions');
        $this->assertNotEmpty($recent);
        $this->assertSame('duel', $recent[0]['type']);
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::USER,
            'influence_profile' => InfluenceProfile::USER_DEFAULT,
        ]);
    }

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
            'name' => 'Player A '.$unique,
            'slug' => 'player-a-'.$unique,
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B '.$unique,
            'slug' => 'player-b-'.$unique,
            'position_id' => $positionId,
        ]);

        $attributeId = DB::table('attributes')->where('key', 'passing')->value('id')
            ?? DB::table('attributes')->insertGetId([
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
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'duel_id' => $duelId,
        ];
    }

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
