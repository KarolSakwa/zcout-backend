<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Models\SyntheticUserSession;
use App\Models\User;
use App\Simulation\Synthetic\ExecuteSyntheticDuelAction;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\StartSyntheticUserSessionAction;
use App\Simulation\Synthetic\SyntheticDailyActivityPlanner;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionActionResult;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class TickSyntheticWorldActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Log::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_target_zero_creates_no_session(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 0,
            'sessions_per_day_max' => 0,
        ]);
        $this->bindFixedRandom(3);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, $result->usersConsidered);
        $this->assertSame(1, $result->inactiveUsersToday);
        $this->assertSame(0, $result->sessionsStarted);
        $this->assertSame(0, SyntheticUserSession::query()->where('user_id', $user->id)->count());
    }

    public function test_session_is_not_created_before_scheduled_start(): void
    {
        config(['app.timezone' => 'UTC']);
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
        ]);
        $this->bindFixedRandom(3);
        $this->bindOkExecuteMock();

        $planner = app(SyntheticDailyActivityPlanner::class);
        $scheduled = $planner->scheduledStartAt((int) $user->id, '2026-07-18', 1, 1);
        Carbon::setTestNow($scheduled->subSecond());

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(0, $result->sessionsStarted);
        $this->assertDatabaseMissing('synthetic_user_sessions', [
            'user_id' => $user->id,
            'activity_date' => '2026-07-18',
        ]);
    }

    public function test_session_is_created_after_scheduled_start_with_stable_seed(): void
    {
        config(['app.timezone' => 'UTC']);
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
            'actions_per_session_min' => 4,
            'actions_per_session_max' => 4,
        ]);
        $this->bindFixedRandom(4);
        $this->bindOkExecuteMock();

        $planner = app(SyntheticDailyActivityPlanner::class);
        $scheduled = $planner->scheduledStartAt((int) $user->id, '2026-07-18', 1, 1);
        $expectedSeed = $planner->sessionSeed((int) $user->id, '2026-07-18', 1);
        Carbon::setTestNow($scheduled);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, $result->sessionsStarted);
        $session = SyntheticUserSession::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('2026-07-18', $session->activity_date->toDateString());
        $this->assertSame(1, $session->daily_session_index);
        $this->assertSame($expectedSeed, $session->session_seed);
        $this->assertSame(4, $session->planned_actions);
        $this->assertNotNull($session->scheduled_start_at);
    }

    public function test_at_most_one_new_session_per_user_per_tick(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 3,
            'sessions_per_day_max' => 3,
            'actions_per_session_min' => 1,
            'actions_per_session_max' => 1,
            'delay_seconds_min' => 1,
            'delay_seconds_max' => 1,
        ]);
        $this->bindFixedRandom(1);
        $this->bindOkExecuteMock();

        app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->whereDate('activity_date', '2026-07-18')
            ->count());
        $this->assertSame(1, (int) SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->value('daily_session_index'));
    }

    public function test_active_manual_session_blocks_world_start(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
        ]);
        $this->bindFixedRandom(2);
        SyntheticUserSession::factory()->for($user)->active()->create([
            'activity_date' => null,
            'daily_session_index' => null,
        ]);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(0, SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('activity_date')
            ->count());
        $this->assertSame(0, $result->sessionsStarted);
    }

    public function test_completed_and_failed_do_not_block_next_index(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 2,
            'sessions_per_day_max' => 2,
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 2,
        ]);
        $this->bindFixedRandom(2);
        $this->bindOkExecuteMock();

        $planner = app(SyntheticDailyActivityPlanner::class);
        SyntheticUserSession::factory()->for($user)->completed()->create([
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
            'session_seed' => $planner->sessionSeed((int) $user->id, '2026-07-18', 1),
            'scheduled_start_at' => now()->subHour(),
        ]);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, $result->sessionsStarted);
        $this->assertDatabaseHas('synthetic_user_sessions', [
            'user_id' => $user->id,
            'activity_date' => '2026-07-18',
            'daily_session_index' => 2,
            'status' => SyntheticSessionStatuses::ACTIVE,
        ]);
    }

    public function test_failed_session_does_not_block_next_index(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 2,
            'sessions_per_day_max' => 2,
        ]);
        $this->bindFixedRandom(2);
        $this->bindOkExecuteMock();

        SyntheticUserSession::factory()->for($user)->failed()->create([
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
            'scheduled_start_at' => now()->subHour(),
        ]);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, $result->sessionsStarted);
        $this->assertDatabaseHas('synthetic_user_sessions', [
            'user_id' => $user->id,
            'daily_session_index' => 2,
        ]);
    }

    public function test_disabled_regular_and_missing_profile_are_skipped(): void
    {
        Carbon::setTestNow('2026-07-18 23:50:00');
        User::factory()->create();
        User::factory()->create(['is_synthetic' => true]);
        $disabled = $this->makeSyntheticUser();
        $disabled->syntheticProfile->update(['is_enabled' => false]);
        $this->bindFixedRandom(2);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(0, $result->usersConsidered);
        $this->assertSame(0, $result->sessionsStarted);
    }

    public function test_invalid_profile_does_not_stop_other_users(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $this->bindFixedRandom(2);
        $this->bindOkExecuteMock();

        $invalid = $this->makeSyntheticUser(['sessions_per_day_min' => 1, 'sessions_per_day_max' => 1]);
        DB::table('synthetic_user_profiles')
            ->where('user_id', $invalid->id)
            ->update(['decision_profile' => 'broken']);

        $valid = $this->makeSyntheticUser(['sessions_per_day_min' => 1, 'sessions_per_day_max' => 1]);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(2, $result->usersConsidered);
        $this->assertGreaterThanOrEqual(1, $result->errors);
        $this->assertSame(1, $result->sessionsStarted);
        $this->assertDatabaseHas('synthetic_user_sessions', [
            'user_id' => $valid->id,
            'daily_session_index' => 1,
        ]);
        $this->assertDatabaseMissing('synthetic_user_sessions', [
            'user_id' => $invalid->id,
        ]);
    }

    public function test_second_tick_does_not_duplicate_daily_session(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
            'actions_per_session_min' => 5,
            'actions_per_session_max' => 5,
            'delay_seconds_min' => 60,
            'delay_seconds_max' => 60,
        ]);
        $this->bindFixedRandom(5, 60);
        $this->bindOkExecuteMock();

        app(TickSyntheticWorldAction::class)->execute();
        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(0, $result->sessionsStarted);
        $this->assertSame(1, SyntheticUserSession::query()
            ->where('user_id', $user->id)
            ->whereDate('activity_date', '2026-07-18')
            ->count());
    }

    public function test_unique_conflict_is_handled_idempotently_by_tick(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
        ]);
        $this->bindFixedRandom(2);
        $this->bindOkExecuteMock();

        $start = new class (
            app(\App\Simulation\Synthetic\ValidateSyntheticUserProfile::class),
            app(RandomIntRange::class),
        ) extends StartSyntheticUserSessionAction {
            public int $attempts = 0;

            public function execute(User $user, ?array $worldMetadata = null): SyntheticUserSession
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new UniqueConstraintViolationException(
                        'pgsql',
                        'insert',
                        [],
                        new \PDOException('duplicate key value violates unique constraint "synthetic_user_sessions_daily_unique"'),
                    );
                }

                return parent::execute($user, $worldMetadata);
            }
        };
        $this->app->instance(StartSyntheticUserSessionAction::class, $start);

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(1, $result->sessionStartConflicts);
        $this->assertSame(0, $result->sessionsStarted);
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, SyntheticUserSession::query()->where('user_id', $user->id)->count());
    }

    public function test_unique_constraint_exists_for_daily_identity(): void
    {
        $user = User::factory()->synthetic()->create();
        SyntheticUserSession::factory()->for($user)->create([
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        SyntheticUserSession::factory()->for($user)->create([
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
        ]);
    }

    public function test_tick_advances_due_session_once_and_skips_not_due(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $due = SyntheticUserSession::factory()->due()->create(['planned_actions' => 3]);
        $notDue = SyntheticUserSession::factory()->notDue()->create(['planned_actions' => 3]);
        $this->bindOkExecuteMock();
        $this->bindFixedRandom(10);

        $result = app(TickSyntheticWorldAction::class)->execute(userLimit: 1);

        $due->refresh();
        $notDue->refresh();

        $this->assertSame(1, $result->dueSessionsFound);
        $this->assertSame(1, $result->sessionsAdvanced);
        $this->assertSame(1, $due->completed_actions);
        $this->assertSame(0, $notDue->completed_actions);
    }

    public function test_completed_and_failed_sessions_are_not_advanced(): void
    {
        $completed = SyntheticUserSession::factory()->completed()->create();
        $failed = SyntheticUserSession::factory()->failed()->create();
        $completed->user->syntheticProfile->update([
            'sessions_per_day_min' => 0,
            'sessions_per_day_max' => 0,
        ]);
        $failed->user->syntheticProfile->update([
            'sessions_per_day_min' => 0,
            'sessions_per_day_max' => 0,
        ]);
        $this->bindOkExecuteMock();

        $result = app(TickSyntheticWorldAction::class)->execute();

        $this->assertSame(0, $result->dueSessionsFound);
        $this->assertSame(0, $result->sessionsAdvanced);
    }

    public function test_multiple_due_sessions_are_processed_in_order(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $later = SyntheticUserSession::factory()->create([
            'next_action_at' => now()->subMinutes(1),
            'planned_actions' => 3,
        ]);
        $earlier = SyntheticUserSession::factory()->create([
            'next_action_at' => now()->subMinutes(5),
            'planned_actions' => 3,
        ]);

        $seenUserIds = [];
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function (
                User $user,
                $decisionProfile,
                string $sessionSeed,
                int $actionIndex,
                int $plannedActions,
            ) use (&$seenUserIds): SyntheticSessionActionResult {
                $seenUserIds[] = $user->id;

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
        $this->bindFixedRandom(5);

        app(TickSyntheticWorldAction::class)->execute(userLimit: 1);

        $this->assertSame([$earlier->user_id, $later->user_id], $seenUserIds);
    }

    public function test_unexpected_on_one_session_does_not_block_next(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $first = SyntheticUserSession::factory()->due()->create(['planned_actions' => 2]);
        $second = SyntheticUserSession::factory()->due()->create(['planned_actions' => 2]);

        $calls = 0;
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function () use (&$calls): SyntheticSessionActionResult {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('boom');
                }

                return new SyntheticSessionActionResult(
                    actionIndex: 1,
                    plannedActions: 2,
                    duelId: 1,
                    attributeKey: 'pace',
                    playerAId: 1,
                    playerBId: 2,
                    decision: 'skip',
                    winnerId: null,
                    status: 'ok',
                    reason: 'policy',
                );
            },
        );
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);
        $this->bindFixedRandom(5);

        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('error')->atLeast()->once();
        $logger->shouldIgnoreMissing();
        Log::swap($logger);

        $result = app(TickSyntheticWorldAction::class)->execute(userLimit: 1);

        $first->refresh();
        $second->refresh();

        $this->assertSame(SyntheticSessionStatuses::FAILED, $first->status);
        $this->assertSame(1, $second->completed_actions);
        $this->assertGreaterThanOrEqual(1, $result->errors);
        $this->assertSame(1, $result->sessionsAdvanced);
    }

    public function test_newly_started_session_can_advance_in_same_tick(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-07-18 23:50:00');
        $user = $this->makeSyntheticUser([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 2,
            'delay_seconds_min' => 30,
            'delay_seconds_max' => 30,
        ]);
        $this->bindFixedRandom(2, 30);
        $this->bindOkExecuteMock();

        $result = app(TickSyntheticWorldAction::class)->execute();

        $session = SyntheticUserSession::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($session);
        $this->assertSame(1, $result->sessionsStarted);
        $this->assertSame(1, $result->sessionsAdvanced);
        $this->assertSame(1, $session->completed_actions);
        $this->assertSame(1, $result->votes);
    }

    public function test_same_session_is_not_advanced_twice_in_one_tick(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $session = SyntheticUserSession::factory()->due()->create([
            'planned_actions' => 5,
        ]);
        $this->bindFixedRandom(10);

        $calls = 0;
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function () use (&$calls): SyntheticSessionActionResult {
                $calls++;

                return new SyntheticSessionActionResult(
                    actionIndex: $calls,
                    plannedActions: 5,
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

        app(TickSyntheticWorldAction::class)->execute(userLimit: 1);

        $session->refresh();
        $this->assertSame(1, $calls);
        $this->assertSame(1, $session->completed_actions);
    }

    public function test_manual_start_session_still_has_null_world_metadata(): void
    {
        $user = User::factory()->synthetic()->create();
        $this->bindFixedRandom(3);

        $session = app(StartSyntheticUserSessionAction::class)->execute($user->fresh(['syntheticProfile']));

        $this->assertNull($session->activity_date);
        $this->assertNull($session->daily_session_index);
        $this->assertNull($session->scheduled_start_at);
    }

    private function makeSyntheticUser(array $profileOverrides = []): User
    {
        $user = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        if ($profileOverrides !== []) {
            $user->syntheticProfile->update($profileOverrides);
        }

        return $user->fresh(['syntheticProfile']);
    }

    private function bindFixedRandom(int $actionsOrValue, ?int $delay = null): void
    {
        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(
            function (int $min, int $max) use ($actionsOrValue, $delay): int {
                if ($delay !== null && $min === $delay && $max === $delay) {
                    return $delay;
                }

                if ($min === $actionsOrValue && $max === $actionsOrValue) {
                    return $actionsOrValue;
                }

                return $min;
            },
        );
        $this->app->instance(RandomIntRange::class, $random);
    }

    private function bindOkExecuteMock(): void
    {
        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturnCallback(
            function (
                User $user,
                $decisionProfile,
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
}
