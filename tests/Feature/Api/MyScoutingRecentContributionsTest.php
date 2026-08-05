<?php

namespace Tests\Feature\Api;

use App\Models\ScoutReportSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyScoutingRecentContributionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_contributions_returns_at_most_five_items_mixed_and_sorted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $fixture = $this->createDuelFixture();
        $this->seedUnlockedUser($user);

        for ($i = 0; $i < 3; $i++) {
            $this->insertDuelVote($user->id, $this->createDuelFixture(), now()->subMinutes(10 - $i));
        }

        ScoutReportSubmission::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'player_id' => $fixture['player_a_id'],
            'ratings_count' => 2,
            'pre_overall' => 80.000,
            'post_overall' => 80.500,
            'created_at' => now()->subMinute(),
        ]);

        ScoutReportSubmission::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'player_id' => $fixture['player_b_id'],
            'ratings_count' => 1,
            'pre_overall' => 79.000,
            'post_overall' => 79.200,
            'created_at' => now()->subMinutes(2),
        ]);

        $response = $this->getJson('/api/my-scouting')->assertOk();
        $items = $response->json('recent_contributions');

        $this->assertLessThanOrEqual(5, count($items));
        $this->assertGreaterThanOrEqual(3, count($items));

        $types = collect($items)->pluck('type')->unique()->sort()->values()->all();
        $this->assertContains('duel', $types);
        $this->assertContains('scout_report', $types);

        $timestamps = collect($items)->pluck('created_at')->all();
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    public function test_duel_contribution_uses_selected_player_id_and_asymmetric_deltas(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedUnlockedUser($user);

        $fixture = $this->createDuelFixture();
        DB::table('votes')->insert([
            'source' => 'duel',
            'attribute_id' => $fixture['attribute_id'],
            'duel_id' => $fixture['duel_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_b_id'],
            'user_id' => $user->id,
            'voter_hash' => hash_hmac('sha256', 'user:'.$user->id, (string) config('app.key')),
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'pre_rating_b' => 78.000,
            'post_rating_a' => 79.500,
            'post_rating_b' => 78.500,
            'created_at' => now(),
        ]);

        $item = collect($this->getJson('/api/my-scouting')->json('recent_contributions'))
            ->firstWhere('type', 'duel');

        $this->assertNotNull($item);
        $this->assertArrayHasKey('selected_player_id', $item);
        $this->assertArrayNotHasKey('winner_player_id', $item);
        $this->assertSame($fixture['player_b_id'], $item['selected_player_id']);
        $this->assertSame(-0.5, $item['player_a']['delta']);
        $this->assertSame(0.5, $item['player_b']['delta']);
    }

    public function test_duel_contribution_returns_null_delta_without_snapshot(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedUnlockedUser($user);

        $fixture = $this->createDuelFixture();
        DB::table('votes')->insert([
            'source' => 'duel',
            'attribute_id' => $fixture['attribute_id'],
            'duel_id' => $fixture['duel_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'user_id' => $user->id,
            'voter_hash' => hash_hmac('sha256', 'user:'.$user->id, (string) config('app.key')),
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
            'weight_version' => 1,
            'pre_rating_a' => null,
            'pre_rating_b' => null,
            'post_rating_a' => null,
            'post_rating_b' => null,
            'created_at' => now(),
        ]);

        $item = collect($this->getJson('/api/my-scouting')->json('recent_contributions'))
            ->firstWhere('type', 'duel');

        $this->assertNull($item['player_a']['delta']);
        $this->assertNull($item['player_b']['delta']);
    }

    public function test_historical_direct_vote_without_submission_is_excluded_from_feed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedUnlockedUser($user);

        $fixture = $this->createDuelFixture();
        DB::table('votes')->insert([
            'source' => 'direct',
            'attribute_id' => $fixture['attribute_id'],
            'player_a_id' => $fixture['player_a_id'],
            'user_id' => $user->id,
            'value' => 85,
            'scout_report_submission_id' => null,
            'weight_applied' => 1.0,
            'confidence_weight_applied' => 0.5,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'post_rating_a' => 80.500,
            'created_at' => now(),
        ]);

        $types = collect($this->getJson('/api/my-scouting')->json('recent_contributions'))
            ->pluck('type')
            ->all();

        $this->assertNotContains('direct', $types);
        $this->assertNotContains(null, $types);
    }

    public function test_dashboard_uses_bounded_query_count(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->seedUnlockedUser($user);

        for ($i = 0; $i < 3; $i++) {
            $this->insertDuelVote($user->id, $this->createDuelFixture(), now()->subMinutes($i));
        }

        DB::enableQueryLog();

        $this->getJson('/api/my-scouting')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount);
    }

    private function seedUnlockedUser(User $user): void
    {
        for ($i = DB::table('votes')->where('user_id', $user->id)->count(); $i < 25; $i++) {
            $this->insertDuelVote($user->id, $this->createDuelFixture(), now()->subHours($i + 1));
        }
    }

    private function insertDuelVote(int $userId, array $fixture, $createdAt): void
    {
        DB::table('votes')->insert([
            'source' => 'duel',
            'attribute_id' => $fixture['attribute_id'],
            'duel_id' => $fixture['duel_id'],
            'player_a_id' => $fixture['player_a_id'],
            'player_b_id' => $fixture['player_b_id'],
            'winner_id' => $fixture['player_a_id'],
            'user_id' => $userId,
            'voter_hash' => hash_hmac('sha256', 'user:'.$userId, (string) config('app.key')),
            'weight_applied' => 0.5,
            'confidence_weight_applied' => 0.1,
            'weight_version' => 1,
            'pre_rating_a' => 80.000,
            'pre_rating_b' => 78.000,
            'post_rating_a' => 80.030,
            'post_rating_b' => 77.970,
            'created_at' => $createdAt,
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

        $attributeId = DB::table('attributes')->where('key', 'pace')->value('id')
            ?? DB::table('attributes')->insertGetId([
                'key' => 'pace',
                'label' => 'Pace',
                'group' => 'PACE',
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
