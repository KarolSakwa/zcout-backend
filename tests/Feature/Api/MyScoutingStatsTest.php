<?php

namespace Tests\Feature\Api;

use App\Models\ScoutReportSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyScoutingStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duels_stat_counts_only_duel_source(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);
        $this->insertDirectVote($user->id, $fixture['player_a_id'], $fixture['attribute_id']);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.duels', 25);
    }

    public function test_skip_does_not_increase_duels_stat(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);

        $this->postJson('/api/duels/skip', [
            'duel_id' => $fixture['duel_id'],
        ])->assertOk();

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.duels', 25);
    }

    public function test_players_rated_counts_both_duel_players_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.players_rated', 2);
    }

    public function test_players_rated_includes_direct_vote_player(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $extraFixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);
        $this->insertDirectVote($user->id, $extraFixture['player_a_id'], $extraFixture['attribute_id']);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.players_rated', 3);
    }

    public function test_historical_direct_vote_without_submission_counts_for_players_rated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $extraFixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);
        $this->insertDirectVote($user->id, $extraFixture['player_a_id'], $extraFixture['attribute_id'], null);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.players_rated', 3);
    }

    public function test_scout_reports_counts_submissions_not_direct_vote_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);

        ScoutReportSubmission::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'player_id' => $fixture['player_a_id'],
            'ratings_count' => 3,
            'pre_overall' => 80.000,
            'post_overall' => 80.500,
            'created_at' => now(),
        ]);

        $this->insertDirectVote($user->id, $fixture['player_a_id'], $fixture['attribute_id']);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.scout_reports', 1);
    }

    public function test_skip_only_submission_does_not_increase_scout_reports(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user, $fixture);

        $this->getJson('/api/my-scouting')
            ->assertOk()
            ->assertJsonPath('stats.scout_reports', 0);
    }

    public function test_anon_scout_reports_stat_is_zero(): void
    {
        $anonId = 'my-scouting-anon-scout-reports';
        $this->seedUnlockedAnon($anonId, 25);

        $this->getJson('/api/my-scouting', ['X-Zcout-Anon' => $anonId])
            ->assertOk()
            ->assertJsonPath('stats.scout_reports', 0);
    }

    private function seedUnlockedUser(User $user, ?array $fixture = null): void
    {
        for ($i = DB::table('votes')->where('user_id', $user->id)->count(); $i < 25; $i++) {
            $this->insertDuelVote($user->id, $fixture ?? $this->createDuelFixture());
        }
    }

    private function seedUnlockedAnon(string $anonId, int $target): void
    {
        $voterHash = hash_hmac('sha256', $anonId, (string) config('app.key'));
        $fixture = $this->createDuelFixture();
        $existing = DB::table('votes')->where('voter_hash', $voterHash)->whereNull('user_id')->count();

        for ($i = $existing; $i < $target; $i++) {
            $this->insertDuelVote(null, $fixture, $voterHash);
        }
    }

    private function insertDuelVote(?int $userId, array $fixture, ?string $voterHash = null): void
    {
        if ($voterHash === null) {
            $voterHash = hash_hmac('sha256', 'user:'.$userId, (string) config('app.key'));
        }

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

    private function insertDirectVote(int $userId, int $playerId, int $attributeId, ?string $submissionId = null): void
    {
        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $attributeId,
            'player_a_id' => $playerId,
            'user_id' => $userId,
            'value' => 85,
            'scout_report_submission_id' => $submissionId,
            'weight_applied' => 1.0,
            'confidence_weight_applied' => 0.5,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'post_rating_a' => 80.500,
            'created_at' => now(),
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
