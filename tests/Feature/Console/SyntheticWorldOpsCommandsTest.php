<?php

namespace Tests\Feature\Console;

use App\Actions\Duels\AuthenticatedVoterLockKey;
use App\Models\SyntheticUserSession;
use App\Models\SyntheticWorldRuntimeSettings;
use App\Models\User;
use App\Simulation\Synthetic\GetSyntheticWorldStatusAction;
use App\Simulation\Synthetic\SyntheticDecisionProfiles;
use App\Simulation\Synthetic\SyntheticSessionStatuses;
use App\Simulation\Synthetic\SyntheticWorldRuntime;
use App\Simulation\Synthetic\SyntheticWorldScheduleMutex;
use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class SyntheticWorldOpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'synthetic_world.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_configure_changes_only_explicit_fields_for_archetype(): void
    {
        $expert = User::factory()->synthetic(SyntheticDecisionProfiles::EXPERT)->create();
        $casual = User::factory()->synthetic(SyntheticDecisionProfiles::CASUAL)->create();
        $expertSkip = $expert->syntheticProfile->skip_probability;
        $casualAcc = $casual->syntheticProfile->decision_accuracy;

        $exit = Artisan::call('zcout:synthetic-world:configure', [
            '--archetype' => 'expert',
            '--decision-accuracy' => '0.95',
            '--noise-level' => '0.02',
        ]);

        $this->assertSame(0, $exit);
        $expert->syntheticProfile->refresh();
        $casual->syntheticProfile->refresh();

        $this->assertEqualsWithDelta(0.95, $expert->syntheticProfile->decision_accuracy, 1e-9);
        $this->assertEqualsWithDelta(0.02, $expert->syntheticProfile->noise_level, 1e-9);
        $this->assertEqualsWithDelta($expertSkip, $expert->syntheticProfile->skip_probability, 1e-9);
        $this->assertEqualsWithDelta($casualAcc, $casual->syntheticProfile->decision_accuracy, 1e-9);
    }

    public function test_configure_all_and_pool_and_dry_run(): void
    {
        User::factory()->syntheticPoolMember('default', 1, SyntheticDecisionProfiles::CASUAL)->create();
        User::factory()->synthetic()->create();

        Artisan::call('zcout:synthetic-world:configure', [
            '--pool' => 'default',
            '--skip-probability' => '0.05',
            '--dry-run' => true,
        ]);

        $poolUser = User::query()->where('synthetic_pool_key', 'default')->firstOrFail();
        $this->assertEqualsWithDelta(0.12, $poolUser->syntheticProfile->skip_probability, 1e-9);

        Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--sessions-min' => 3,
            '--sessions-max' => 3,
        ]);

        $this->assertSame(3, $poolUser->syntheticProfile->fresh()->sessions_per_day_min);
        $manual = User::query()->where('is_synthetic', true)->whereNull('synthetic_pool_key')->firstOrFail();
        $this->assertSame(3, $manual->syntheticProfile->fresh()->sessions_per_day_min);
    }

    public function test_configure_validation_and_selector_errors(): void
    {
        User::factory()->synthetic()->create();

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:configure', [
            '--decision-accuracy' => '0.9',
        ]));
        $this->assertStringContainsString('Selector required', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--archetype' => 'expert',
            '--decision-accuracy' => '0.9',
        ]));
        $this->assertStringContainsString('Conflicting filters', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
        ]));
        $this->assertStringContainsString('No configuration changes', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--skip-probability' => '1.5',
        ]));
        $this->assertStringContainsString('between 0 and 1', Artisan::output());

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--sessions-min' => 5,
            '--sessions-max' => 1,
        ]));
    }

    public function test_reset_daily_plan_cancels_active_world_sessions(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->due()->create();

        $exit = Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--sessions-min' => 5,
            '--sessions-max' => 5,
            '--reset-daily-plan' => true,
        ]);

        $this->assertSame(0, $exit);
        $session->refresh();
        $this->assertSame(SyntheticSessionStatuses::CANCELLED, $session->status);
        $this->assertSame('daily_plan_reset', $session->last_action_reason);
        $this->assertSame(5, $user->syntheticProfile->fresh()->sessions_per_day_min);
    }

    public function test_stop_finish_and_cancel_and_start_runtime(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->due()->create();

        $this->assertSame(0, Artisan::call('zcout:synthetic-world:stop', ['--finish-active' => true]));
        $runtime = app(SyntheticWorldRuntime::class);
        $this->assertFalse($runtime->runtimeEnabled());
        $this->assertTrue($runtime->allowsAdvancingSessions());
        $this->assertFalse($runtime->allowsStartingSessions());
        $this->assertSame(SyntheticSessionStatuses::ACTIVE, $session->fresh()->status);

        $this->assertSame(1, Artisan::call('zcout:synthetic-world:stop', [
            '--finish-active' => true,
            '--cancel-active' => true,
        ]));

        $this->assertSame(0, Artisan::call('zcout:synthetic-world:stop', ['--cancel-active' => true]));
        $this->assertSame(SyntheticSessionStatuses::CANCELLED, $session->fresh()->status);
        $this->assertFalse($runtime->allowsAdvancingSessions());

        $this->assertSame(0, Artisan::call('zcout:synthetic-world:start'));
        $this->assertTrue($runtime->runtimeEnabled());
        $this->assertTrue($runtime->allowsStartingSessions());
    }

    public function test_start_fails_when_environment_disabled(): void
    {
        config(['synthetic_world.enabled' => false]);

        $exit = Artisan::call('zcout:synthetic-world:start');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('SYNTHETIC_WORLD_ENABLED=true', Artisan::output());
    }

    public function test_tick_heartbeat_success_and_env_disabled_noop(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        User::factory()->synthetic()->create([
            // ensure profile exists via factory afterCreating
        ]);
        User::query()->where('is_synthetic', true)->firstOrFail()->syntheticProfile->update([
            'sessions_per_day_min' => 0,
            'sessions_per_day_max' => 0,
        ]);

        app(TickSyntheticWorldAction::class)->execute();
        $settings = SyntheticWorldRuntimeSettings::query()->findOrFail(1);
        $this->assertNotNull($settings->tick_started_at);
        $this->assertNotNull($settings->tick_finished_at);
        $this->assertNull($settings->tick_failed_at);

        config(['synthetic_world.enabled' => false]);
        DB::table('synthetic_world_runtime_settings')->update([
            'tick_started_at' => null,
            'tick_finished_at' => null,
        ]);
        $result = app(TickSyntheticWorldAction::class)->execute();
        $this->assertSame(0, $result->sessionsStarted);
        $settings->refresh();
        $this->assertNull($settings->tick_started_at);
    }

    public function test_status_reports_runtime_layers_and_daily_plan_exhausted_warning(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
        ]);
        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->completed()->create();

        Artisan::call('zcout:synthetic-world:status');
        $output = Artisan::output();

        $this->assertStringContainsString('Environment automation: enabled', $output);
        $this->assertStringContainsString('Runtime automation: running', $output);
        $this->assertStringContainsString('Effective automation: enabled', $output);
        $this->assertStringContainsString('daily_plan_exhausted', $output);
    }

    public function test_reset_daily_plan_refuses_when_voter_lock_is_held(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        $session = SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->due()->create();
        $originalSessionsMin = $user->syntheticProfile->sessions_per_day_min;

        $attributeId = DB::table('attributes')->insertGetId([
            'key' => 'pace',
            'label' => 'Pace',
            'group' => 'PACE',
            'order' => 1,
            'scope' => 'both',
        ]);
        $playerAId = DB::table('players')->insertGetId([
            'name' => 'Player A',
            'slug' => 'player-a-ops-lock',
            'club' => 'Club A',
            'number' => 1,
        ]);
        $playerBId = DB::table('players')->insertGetId([
            'name' => 'Player B',
            'slug' => 'player-b-ops-lock',
            'club' => 'Club B',
            'number' => 2,
        ]);
        $duelId = DB::table('duels')->insertGetId([
            'player_a_id' => $playerAId,
            'player_b_id' => $playerBId,
            'attribute_id' => $attributeId,
            'created_at' => now(),
        ]);

        DB::table('voter_duel_locks')->insert([
            'voter_hash' => AuthenticatedVoterLockKey::forUserId($user->id),
            'duel_id' => $duelId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('zcout:synthetic-world:configure', [
            '--all' => true,
            '--sessions-min' => 5,
            '--sessions-max' => 5,
            '--reset-daily-plan' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot safely reset daily plan', Artisan::output());
        $this->assertSame(SyntheticSessionStatuses::ACTIVE, $session->fresh()->status);
        $this->assertSame($originalSessionsMin, $user->syntheticProfile->fresh()->sessions_per_day_min);
    }

    public function test_tick_heartbeat_records_failure(): void
    {
        $runtime = $this->createMock(SyntheticWorldRuntime::class);
        $runtime->method('environmentEnabled')->willReturn(true);
        $runtime->method('allowsStartingSessions')->willThrowException(new RuntimeException('boom'));
        $runtime->expects($this->once())->method('markTickStarted');
        $runtime->expects($this->once())->method('markTickFailed')->with('boom');
        $runtime->expects($this->never())->method('markTickFinished');

        $action = new TickSyntheticWorldAction(
            app(\App\Simulation\Synthetic\SyntheticDailyActivityPlanner::class),
            app(\App\Simulation\Synthetic\StartSyntheticUserSessionAction::class),
            app(\App\Simulation\Synthetic\AdvanceSyntheticUserSessionAction::class),
            app(\App\Simulation\Synthetic\ValidateSyntheticUserProfile::class),
            $runtime,
        );

        try {
            $action->execute();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }
    }

    public function test_status_reports_no_progress_when_due_and_stale_heartbeat(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $user = User::factory()->synthetic()->create();
        SyntheticUserSession::factory()->for($user)->world('2026-07-18', 1)->due()->create([
            'next_action_at' => '2026-07-18 11:50:00',
        ]);

        DB::table('synthetic_world_runtime_settings')->updateOrInsert(
            ['id' => 1],
            [
                'runtime_enabled' => true,
                'last_progress_at' => '2026-07-18 11:50:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $status = app(GetSyntheticWorldStatusAction::class)->execute();
        $this->assertContains('no_progress', array_column($status->warnings, 'code'));
        $this->assertNotContains('daily_plan_exhausted', array_column($status->warnings, 'code'));
    }

    public function test_stale_mutex_clear_and_fresh_mutex_refusal(): void
    {
        $mutex = $this->createMock(SyntheticWorldScheduleMutex::class);
        $mutex->expects($this->once())->method('clearIfStale')->willReturn([
            'cleared' => false,
            'reason' => 'mutex_not_stale',
        ]);
        $this->app->instance(SyntheticWorldScheduleMutex::class, $mutex);

        $exit = Artisan::call('zcout:synthetic-world:start', ['--clear-stale-mutex' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not clearly stale', Artisan::output());

        $mutexOk = $this->createMock(SyntheticWorldScheduleMutex::class);
        $mutexOk->method('clearIfStale')->willReturn([
            'cleared' => true,
            'reason' => 'stale_mutex_cleared',
        ]);
        $this->app->instance(SyntheticWorldScheduleMutex::class, $mutexOk);

        // Avoid real tick work; bind a noop tick.
        $tick = $this->createMock(TickSyntheticWorldAction::class);
        $tick->method('execute')->willReturn(new \App\Simulation\Synthetic\SyntheticWorldTickResult());
        $this->app->instance(TickSyntheticWorldAction::class, $tick);

        $exit = Artisan::call('zcout:synthetic-world:start', ['--clear-stale-mutex' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('stale_mutex_cleared', Artisan::output());
    }

    public function test_mutex_staleness_requires_old_unfinished_heartbeat(): void
    {
        Carbon::setTestNow('2026-07-18 12:00:00');
        $runtime = app(SyntheticWorldRuntime::class);
        $settings = $runtime->current();
        $settings->tick_started_at = now()->subSeconds(SyntheticWorldScheduleMutex::STALE_TICK_SECONDS + 10);
        $settings->tick_finished_at = null;
        $settings->save();

        $mutex = app(SyntheticWorldScheduleMutex::class);
        $this->assertTrue($mutex->heartbeatIndicatesStale());

        $settings->tick_finished_at = now()->subSeconds(5);
        $settings->save();
        $this->assertFalse($mutex->heartbeatIndicatesStale());

        $settings->tick_finished_at = null;
        $settings->tick_started_at = now()->subSeconds(10);
        $settings->save();
        $this->assertFalse($mutex->heartbeatIndicatesStale());
    }
}
