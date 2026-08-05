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

class MyScoutingDashboardAccessTest extends TestCase
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

    public function test_locked_dashboard_returns_progress_only_at_24_contributions(): void
    {
        $anonId = 'my-scouting-locked-24';
        $this->seedDuelVotes($anonId, 24);

        $this->getJson('/api/my-scouting', ['X-Zcout-Anon' => $anonId])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 24)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', false)
            ->assertJsonPath('stats', null)
            ->assertJsonPath('recent_contributions', []);
    }

    public function test_unlocked_dashboard_returns_stats_and_recent_at_25_contributions(): void
    {
        $anonId = 'my-scouting-unlocked-25';
        $this->seedDuelVotes($anonId, 25);

        $this->getJson('/api/my-scouting', ['X-Zcout-Anon' => $anonId])
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 25)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.progress_target', 100)
            ->assertJsonPath('scouting_progress.stage_progress', 0)
            ->assertJsonPath('scouting_progress.stage_target', 100)
            ->assertJsonStructure([
                'stats' => ['duels', 'players_rated', 'scout_reports'],
                'recent_contributions',
            ]);
    }

    public function test_dashboard_still_works_at_100_plus_contributions_without_your_impact_unlock_field(): void
    {
        $user = $this->createUser();
        $this->seedDuelVotesForUser($user->id, 134);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('scouting_progress.contributions', 134)
            ->assertJsonPath('scouting_progress.my_scouting_unlocked', true)
            ->assertJsonPath('scouting_progress.progress_target', 100)
            ->assertJsonPath('scouting_progress.stage_progress', 100)
            ->assertJsonPath('scouting_progress.stage_target', 100);

        $this->assertArrayNotHasKey('your_impact_unlocked', $response->json('scouting_progress'));
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::USER,
            'influence_profile' => InfluenceProfile::USER_DEFAULT,
        ]);
    }

    private function seedDuelVotes(string $anonId, int $count): void
    {
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));

        for ($i = 0; $i < $count; $i++) {
            $this->insertDuelVote($voterHash, null);
        }
    }

    private function seedDuelVotesForUser(int $userId, int $count): void
    {
        $voterHash = hash_hmac('sha256', 'user:'.$userId, (string) config('app.key'));

        for ($i = 0; $i < $count; $i++) {
            $this->insertDuelVote($voterHash, $userId);
        }
    }

    private function insertDuelVote(string $voterHash, ?int $userId): void
    {
        $fixture = $this->createDuelFixture();

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
            'created_at' => now()->subMinutes(random_int(1, 1000)),
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
}
