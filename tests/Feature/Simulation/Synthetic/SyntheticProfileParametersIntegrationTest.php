<?php

namespace Tests\Feature\Simulation\Synthetic;

use App\Events\PlayerAttributeRatingUpdated;
use App\Events\PlayerOverallUpdated;
use App\Events\RecentVoteCreated;
use App\Events\TopMoversMaybeChanged;
use App\Models\SyntheticUserSession;
use App\Models\User;
use App\Services\Ranking\AttributeRankingService;
use App\Simulation\Synthetic\AdvanceSyntheticUserSessionAction;
use App\Simulation\Synthetic\ExecuteSyntheticDuelAction;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\RunSyntheticUserSessionAction;
use App\Simulation\Synthetic\RunWithAuthenticatedUser;
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionActionResult;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SyntheticProfileParametersIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            RecentVoteCreated::class,
            TopMoversMaybeChanged::class,
            PlayerAttributeRatingUpdated::class,
            PlayerOverallUpdated::class,
        ]);

        config([
            'zcout_matchmaking.intent_mix' => [
                'calibration' => 0.0,
                'production' => 1.0,
            ],
            'zcout_matchmaking.production_tier_mix' => [
                'A' => 1.0,
                'B' => 0.0,
                'C' => 0.0,
            ],
            'zcout_matchmaking.production_position_profile_mix' => [
                'exact' => 1.0,
                'adjacent' => 0.0,
                'same_side' => 0.0,
                'any' => 0.0,
            ],
            'zcout_matchmaking.production_gap_profile_mix' => [
                'close' => 0.0,
                'medium' => 1.0,
            ],
            'zcout_matchmaking.attribute_scope_mix' => [
                'both' => 1.0,
                'gk' => 0.0,
            ],
        ]);

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')->andReturn([
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Log::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_skip_probability_one_results_in_canonical_skip(): void
    {
        $fixture = $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'skip_probability' => 1.0,
            'decision_accuracy' => 1.0,
            'noise_level' => 0.0,
        ]);

        $result = $this->executeAuthenticated($user, '00000000-0000-4000-8000-000000000101');

        $this->assertSame('ok', $result->status);
        $this->assertSame('skip', $result->decision);
        $this->assertSame('policy', $result->reason);
        $this->assertSame(0, DB::table('votes')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('duel_skips', [
            'voter_hash' => 'user:'.$user->id,
            'duel_id' => $result->duelId,
        ]);
        $this->assertDatabaseMissing('voter_duel_locks', [
            'voter_hash' => 'user:'.$user->id,
        ]);
        $this->assertSame($fixture['player_a_id'], $result->playerAId);
    }

    public function test_accuracy_one_votes_for_higher_live_rating(): void
    {
        $fixture = $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'skip_probability' => 0.0,
            'decision_accuracy' => 1.0,
            'noise_level' => 0.0,
        ]);

        $result = $this->executeAuthenticated($user, '00000000-0000-4000-8000-000000000102');

        $this->assertSame('ok', $result->status);
        $this->assertSame('vote', $result->decision);
        $this->assertSame($fixture['player_b_id'], $result->winnerId);
        $this->assertDatabaseHas('votes', [
            'user_id' => $user->id,
            'winner_id' => $fixture['player_b_id'],
            'source' => 'duel',
        ]);
    }

    public function test_accuracy_zero_votes_for_lower_live_rating(): void
    {
        $fixture = $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'skip_probability' => 0.0,
            'decision_accuracy' => 0.0,
            'noise_level' => 0.0,
        ]);

        $result = $this->executeAuthenticated($user, '00000000-0000-4000-8000-000000000103');

        $this->assertSame('ok', $result->status);
        $this->assertSame('vote', $result->decision);
        $this->assertSame($fixture['player_a_id'], $result->winnerId);
    }

    public function test_profile_update_affects_next_advance_with_stable_session_seed(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'skip_probability' => 0.0,
            'decision_accuracy' => 1.0,
            'noise_level' => 0.0,
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 2,
            'delay_seconds_min' => 1,
            'delay_seconds_max' => 1,
        ]);
        $this->bindFixedRandom(2, 1);

        $session = app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));
        $seed = $session->session_seed;

        $first = app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);
        $this->assertSame('vote', $first->action->decision);
        $this->assertSame($seed, $session->fresh()->session_seed);

        DB::table('votes')->where('user_id', $user->id)->delete();
        DB::table('duel_skips')->where('voter_hash', 'user:'.$user->id)->delete();
        DB::table('voter_duel_locks')->where('voter_hash', 'user:'.$user->id)->delete();

        $user->syntheticProfile->update([
            'skip_probability' => 1.0,
        ]);

        Carbon::setTestNow('2026-07-18 12:00:01');
        $second = app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);

        $this->assertSame($seed, $session->fresh()->session_seed);
        $this->assertSame('skip', $second->action->decision);
        $this->assertSame('policy', $second->action->reason);
    }

    public function test_same_archetype_different_params_can_diverge(): void
    {
        $this->seedMatchmakingFixture();

        $accurate = User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();
        $accurate->syntheticProfile->update([
            'skip_probability' => 0.0,
            'decision_accuracy' => 1.0,
            'noise_level' => 0.0,
        ]);

        $inaccurate = User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();
        $inaccurate->syntheticProfile->update([
            'skip_probability' => 0.0,
            'decision_accuracy' => 0.0,
            'noise_level' => 0.0,
        ]);

        $seed = '00000000-0000-4000-8000-000000000104';

        $resultAccurate = $this->executeAuthenticated($accurate, $seed);

        DB::table('voter_duel_locks')->delete();

        $resultInaccurate = $this->executeAuthenticated($inaccurate, $seed);

        $this->assertSame('casual', $accurate->syntheticProfile->decision_profile);
        $this->assertSame('casual', $inaccurate->syntheticProfile->decision_profile);
        $this->assertSame('vote', $resultAccurate->decision);
        $this->assertSame('vote', $resultInaccurate->decision);
        $this->assertNotSame($resultAccurate->winnerId, $resultInaccurate->winnerId);
    }

    public function test_invalid_profile_params_fail_persistent_session(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => 1,
            'actions_per_session_max' => 1,
            'skip_probability' => 0.0,
        ]);
        $this->bindFixedRandom(1);

        $session = app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));

        DB::table('synthetic_user_profiles')
            ->where('user_id', $user->id)
            ->update(['skip_probability' => 1.5]);

        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->atLeast()->once();
        $logger->shouldIgnoreMissing();
        Log::swap($logger);

        try {
            app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);
            $this->fail('Expected DomainException');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('skipProbability', $exception->getMessage());
        }

        $session->refresh();
        $this->assertSame(SyntheticSessionStatuses::FAILED, $session->status);
        $this->assertSame('unexpected_error', $session->last_action_reason);
    }

    public function test_run_session_uses_profile_model_parameters(): void
    {
        $this->seedMatchmakingFixture();
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'skip_probability' => 1.0,
            'decision_accuracy' => 1.0,
            'noise_level' => 0.0,
        ]);

        $summary = app(RunSyntheticUserSessionAction::class)->execute(
            user: $user,
            profile: $user->fresh(['syntheticProfile'])->syntheticProfile,
            actions: 1,
            sessionId: '00000000-0000-4000-8000-000000000105',
        );

        $this->assertSame(1, $summary->skips);
        $this->assertSame(0, $summary->votes);
        $this->assertSame(0, DB::table('votes')->where('user_id', $user->id)->count());
    }

    private function executeAuthenticated(User $user, string $sessionSeed): SyntheticSessionActionResult
    {
        $profile = $user->fresh(['syntheticProfile'])->syntheticProfile;
        $this->assertNotNull($profile);

        return app(RunWithAuthenticatedUser::class)->execute(
            $user,
            fn (): SyntheticSessionActionResult => app(ExecuteSyntheticDuelAction::class)->execute(
                user: $user,
                profile: $profile,
                sessionSeed: $sessionSeed,
                actionIndex: 1,
                plannedActions: 1,
            ),
        );
    }

    private function bindFixedRandom(int $actions, int $delay = 1): void
    {
        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(
            function (int $min, int $max) use ($actions, $delay): int {
                if ($min === $actions && $max === $actions) {
                    return $actions;
                }

                if ($min === $delay && $max === $delay) {
                    return $delay;
                }

                return $min;
            },
        );
        $this->app->instance(RandomIntRange::class, $random);
    }

    /**
     * @return array{attribute_id: int, player_a_id: int, player_b_id: int}
     */
    private function seedMatchmakingFixture(): array
    {
        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'code' => 'ENG',
            'name' => 'ENGLAND',
            'iso2' => 'GB',
            'flag_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubAId = DB::table('clubs')->insertGetId([
            'name' => 'Club A',
            'slug' => 'club-a',
            'color_primary' => '#111111',
            'color_secondary' => '#222222',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clubBId = DB::table('clubs')->insertGetId([
            'name' => 'Club B',
            'slug' => 'club-b',
            'color_primary' => '#333333',
            'color_secondary' => '#444444',
            'color_tertiary' => '#FFFFFF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = DB::table('positions')->insertGetId([
            'key' => 'RB',
            'label' => 'Right Back',
            'short_label' => 'RB',
            'group' => 'DEF',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a',
            'club' => 'Club A',
            'number' => 2,
            'club_id' => $clubAId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b',
            'club' => 'Club B',
            'number' => 22,
            'club_id' => $clubBId,
            'country_id' => $countryId,
            'position_id' => $positionId,
        ]);

        DB::table('player_reputation_stats')->insert([
            [
                'player_id' => $playerAId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.0000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 45,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
            [
                'player_id' => $playerBId,
                'minutes_90d' => 100,
                'minutes_long_term' => 1000,
                'player_rep' => 1.1000,
                'is_long_tail' => false,
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'fpl_now_cost' => 46,
                'fpl_selected_by_percent' => 0,
                'tier' => 'A',
            ],
        ]);

        DB::table('player_attribute_ratings')->insert([
            [
                'player_id' => $playerAId,
                'attribute_id' => $attributeId,
                'rating' => 42,
                'votes_count' => 0,
                'confidence' => 50,
            ],
            [
                'player_id' => $playerBId,
                'attribute_id' => $attributeId,
                'rating' => 50,
                'votes_count' => 0,
                'confidence' => 50,
            ],
        ]);

        return [
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ];
    }
}
