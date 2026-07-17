<?php

namespace Tests\Feature\Console;

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
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionActionResult;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class AdvanceSyntheticUserSessionCommandTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Log::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_command_fails_for_missing_session(): void
    {
        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => 999999,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('was not found', Artisan::output());
    }

    public function test_one_command_executes_exactly_one_action(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(3, delayMin: 10, delayMax: 10);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $session->completed_actions);
        $this->assertSame(SyntheticSessionStatuses::ACTIVE, $session->status);
        $this->assertTrue($session->next_action_at->equalTo(Carbon::parse('2026-07-17 12:00:10')));
        $this->assertStringContainsString('[1/3]', Artisan::output());
        $this->assertStringNotContainsString('[2/3]', Artisan::output());
    }

    public function test_advance_before_next_action_at_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(2, delayMin: 30, delayMax: 30);

        Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();
        $this->assertSame(1, $session->completed_actions);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();
        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $session->completed_actions);
        $this->assertStringContainsString('is not due yet', Artisan::output());
    }

    public function test_advance_works_after_time_passes(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $session = $this->startSessionWithPlannedActions(2, delayMin: 15, delayMax: 15);
        $this->bindOkExecuteMock();

        app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);
        $session->refresh();
        $this->assertSame(1, $session->completed_actions);
        $this->assertTrue($session->next_action_at->equalTo(Carbon::parse('2026-07-17 12:00:15')));

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not due yet', Artisan::output());

        Carbon::setTestNow('2026-07-17 12:00:15');

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();
        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $session->completed_actions);
        $this->assertSame(SyntheticSessionStatuses::COMPLETED, $session->status);
        $this->assertNull($session->next_action_at);
        $this->assertNotNull($session->completed_at);
    }

    public function test_last_action_completes_session(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(1);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertSame(SyntheticSessionStatuses::COMPLETED, $session->status);
        $this->assertSame(1, $session->completed_actions);
        $this->assertNull($session->next_action_at);
        $this->assertNotNull($session->completed_at);
        $this->assertStringContainsString('Status: completed', $output);
        $this->assertStringContainsString('Completed at:', $output);
    }

    public function test_completed_session_cannot_be_advanced(): void
    {
        $session = SyntheticUserSession::factory()->completed()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot advance completed', Artisan::output());
    }

    public function test_failed_session_cannot_be_advanced(): void
    {
        $session = SyntheticUserSession::factory()->failed()->create();

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot advance failed', Artisan::output());
    }

    public function test_missing_live_rating_counts_as_completed_skip(): void
    {
        config([
            'zcout_matchmaking.intent_mix' => [
                'calibration' => 1.0,
                'production' => 0.0,
            ],
        ]);

        Carbon::setTestNow('2026-07-17 12:00:00');
        $fixture = $this->seedMatchmakingFixture(includeRatings: true);
        DB::table('player_attribute_ratings')
            ->where('player_id', $fixture['player_b_id'])
            ->delete();

        $session = $this->startSessionWithPlannedActions(2, delayMin: 5, delayMax: 5);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $session->completed_actions);
        $this->assertSame('ok', $session->last_action_status);
        $this->assertSame('missing_live_rating', $session->last_action_reason);
        $this->assertDatabaseHas('duel_skips', [
            'voter_hash' => 'user:' . $session->user_id,
        ]);
    }

    public function test_no_duel_available_does_not_increment_and_stays_active(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        // No matchmaking fixture → no duel
        $session = $this->startSessionWithPlannedActions(3, delayMin: 8, delayMax: 8);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $session->completed_actions);
        $this->assertSame(SyntheticSessionStatuses::ACTIVE, $session->status);
        $this->assertSame('failure', $session->last_action_status);
        $this->assertSame('no_duel_available', $session->last_action_reason);
        $this->assertTrue($session->next_action_at->equalTo(Carbon::parse('2026-07-17 12:00:08')));
    }

    public function test_unexpected_exception_marks_session_failed(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(3);

        $this->mock(AttributeRankingService::class, function ($mock): void {
            $mock->shouldReceive('getBadgeData')
                ->andThrow(new RuntimeException('boom'));
        });

        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($session): bool {
                return $message === 'synthetic.session.unexpected_error'
                    && ($context['session_id'] ?? null) === $session->id
                    && ($context['user_id'] ?? null) === $session->user_id
                    && ($context['action_index'] ?? null) === 1
                    && ($context['exception'] ?? null) === RuntimeException::class
                    && ($context['message'] ?? null) === 'boom';
            });
        $logger->shouldIgnoreMissing();
        Log::swap($logger);

        $exitCode = Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $session->refresh();

        $this->assertSame(1, $exitCode);
        $this->assertSame(SyntheticSessionStatuses::FAILED, $session->status);
        $this->assertNull($session->next_action_at);
        $this->assertNotNull($session->completed_at);
        $this->assertSame('failure', $session->last_action_status);
        $this->assertSame('unexpected_error', $session->last_action_reason);
        $this->assertStringContainsString('Session aborted:', Artisan::output());
    }

    public function test_session_seed_is_stable_and_action_index_grows(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => 3,
            'actions_per_session_max' => 3,
            'delay_seconds_min' => 1,
            'delay_seconds_max' => 1,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(function (int $min, int $max): int {
            if ($min === 3 && $max === 3) {
                return 3;
            }

            return 1;
        });
        $this->app->instance(RandomIntRange::class, $random);

        $session = app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));
        $seed = $session->session_seed;

        $seenIndexes = [];
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function (
                User $u,
                string $profile,
                string $sessionSeed,
                int $actionIndex,
                int $plannedActions,
            ) use ($seed, &$seenIndexes): SyntheticSessionActionResult {
                $this->assertSame($seed, $sessionSeed);
                $seenIndexes[] = $actionIndex;

                return new SyntheticSessionActionResult(
                    actionIndex: $actionIndex,
                    plannedActions: $plannedActions,
                    duelId: 1,
                    attributeKey: 'pace',
                    playerAId: 1,
                    playerBId: 2,
                    decision: 'vote',
                    winnerId: 1,
                    status: 'ok',
                );
            });
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);

        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00')->addSeconds($i));
            app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);
            $session->refresh();
            $this->assertSame($seed, $session->session_seed);
        }

        $this->assertSame([1, 2, 3], $seenIndexes);
        $this->assertSame(SyntheticSessionStatuses::COMPLETED, $session->status);
    }

    public function test_vote_uses_same_user_identity(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(1);

        Artisan::call('zcout:synthetic-users:advance-session', [
            '--session-id' => $session->id,
        ]);

        $this->assertDatabaseHas('votes', [
            'user_id' => $session->user_id,
            'source' => 'duel',
        ]);
    }

    public function test_advance_uses_lock_for_update_on_session_row(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');
        $this->seedMatchmakingFixture(includeRatings: true);
        $session = $this->startSessionWithPlannedActions(1);

        $capturedLockSql = null;
        DB::listen(function ($query) use (&$capturedLockSql): void {
            $sql = strtolower($query->sql);
            if (
                str_contains($sql, 'synthetic_user_sessions')
                && str_contains($sql, 'for update')
            ) {
                $capturedLockSql = $query->sql;
            }
        });

        app(AdvanceSyntheticUserSessionAction::class)->execute($session->id);

        $this->assertNotNull(
            $capturedLockSql,
            'Expected lockForUpdate on synthetic_user_sessions. Full parallel two-connection concurrency is not asserted here.',
        );
        $this->assertStringContainsString('for update', strtolower($capturedLockSql));
    }

    public function test_command_has_no_user_id_option(): void
    {
        $definition = app(\App\Console\Commands\AdvanceSyntheticUserSessionCommand::class)->getDefinition();

        $this->assertTrue($definition->hasOption('session-id'));
        $this->assertFalse($definition->hasOption('user-id'));
    }

    private function bindOkExecuteMock(): void
    {
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function (
                User $user,
                string $decisionProfile,
                string $sessionSeed,
                int $actionIndex,
                int $plannedActions,
            ): SyntheticSessionActionResult {
                return new SyntheticSessionActionResult(
                    actionIndex: $actionIndex,
                    plannedActions: $plannedActions,
                    duelId: 1,
                    attributeKey: 'pace',
                    playerAId: 1,
                    playerBId: 2,
                    decision: 'vote',
                    winnerId: 1,
                    status: 'ok',
                );
            },
        );
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);
    }

    private function startSessionWithPlannedActions(
        int $plannedActions,
        int $delayMin = 6,
        int $delayMax = 6,
    ): SyntheticUserSession {
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $user->syntheticProfile->update([
            'actions_per_session_min' => $plannedActions,
            'actions_per_session_max' => $plannedActions,
            'delay_seconds_min' => $delayMin,
            'delay_seconds_max' => $delayMax,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(
            function (int $min, int $max) use ($plannedActions, $delayMin, $delayMax): int {
                if ($min === $plannedActions && $max === $plannedActions) {
                    return $plannedActions;
                }

                if ($min === $delayMin && $max === $delayMax) {
                    return $delayMin;
                }

                return $min;
            },
        );
        $this->app->instance(RandomIntRange::class, $random);

        return app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));
    }

    /**
     * @return array{
     *     attribute_id: int,
     *     player_a_id: int,
     *     player_b_id: int
     * }
     */
    private function seedMatchmakingFixture(bool $includeRatings): array
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

        if ($includeRatings) {
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
        }

        return [
            'attribute_id' => $attributeId,
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
        ];
    }
}
