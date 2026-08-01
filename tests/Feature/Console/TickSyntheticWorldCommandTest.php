<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Simulation\Synthetic\ExecuteSyntheticDuelAction;
use App\Simulation\Synthetic\RandomIntRange;
use App\Simulation\Synthetic\SyntheticSessionActionResult;
use App\Simulation\Synthetic\SyntheticWorldTickResult;
use App\Simulation\Synthetic\TickSyntheticWorldAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class TickSyntheticWorldCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_prints_summary_and_returns_success(): void
    {
        Carbon::setTestNow('2026-07-18 08:20:00');
        config(['app.timezone' => 'UTC']);

        $this->bindTickResult(new SyntheticWorldTickResult(
            usersConsidered: 10,
            inactiveUsersToday: 3,
            sessionsStarted: 2,
            sessionStartConflicts: 0,
            dueSessionsFound: 4,
            sessionsAdvanced: 4,
            votes: 2,
            skips: 1,
            actionFailures: 1,
            completedSessions: 1,
            failedSessions: 0,
            errors: 0,
        ));

        $this->artisan('zcout:synthetic-world:tick')
            ->assertSuccessful()
            ->expectsOutputToContain('Synthetic world tick started')
            ->expectsOutputToContain('Timezone: UTC')
            ->expectsOutputToContain('Users considered: 10')
            ->expectsOutputToContain('Inactive today: 3')
            ->expectsOutputToContain('Sessions started: 2')
            ->expectsOutputToContain('Start conflicts: 0')
            ->expectsOutputToContain('Due sessions found: 4')
            ->expectsOutputToContain('Sessions advanced: 4')
            ->expectsOutputToContain('Votes: 2')
            ->expectsOutputToContain('Skips: 1')
            ->expectsOutputToContain('Action failures: 1')
            ->expectsOutputToContain('Completed sessions: 1')
            ->expectsOutputToContain('Failed sessions: 0')
            ->expectsOutputToContain('Errors: 0')
            ->expectsOutputToContain('Synthetic world tick completed');
    }

    public function test_command_returns_success_with_partial_action_failures(): void
    {
        $this->bindTickResult(new SyntheticWorldTickResult(
            sessionsAdvanced: 3,
            actionFailures: 2,
            errors: 1,
        ));

        $this->artisan('zcout:synthetic-world:tick')
            ->assertSuccessful()
            ->expectsOutputToContain('Action failures: 2')
            ->expectsOutputToContain('Errors: 1');
    }

    public function test_command_returns_failure_on_critical_tick_error(): void
    {
        $this->mock(TickSyntheticWorldAction::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andThrow(new RuntimeException('db down'));
        });

        $exitCode = Artisan::call('zcout:synthetic-world:tick');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Synthetic world tick failed', $output);
        $this->assertStringContainsString('db down', $output);
    }

    public function test_command_does_not_register_scheduler_entry_when_disabled(): void
    {
        config(['synthetic_world.enabled' => false]);
        $this->app->forgetInstance(\Illuminate\Console\Scheduling\Schedule::class);

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(
            static fn ($event): string => (string) ($event->command ?? $event->description ?? ''),
        );

        $this->assertFalse(
            $events->contains(static fn (string $command): bool => str_contains($command, 'synthetic-world:tick')),
        );
    }

    public function test_command_runs_real_tick_for_enabled_synthetic_user(): void
    {
        config([
            'app.timezone' => 'UTC',
            'synthetic_world.enabled' => true,
            'synthetic_world.timezone' => 'UTC',
        ]);
        Carbon::setTestNow('2026-07-18 23:50:00');

        $user = User::factory()->synthetic()->create();
        $user->syntheticProfile->update([
            'sessions_per_day_min' => 1,
            'sessions_per_day_max' => 1,
            'actions_per_session_min' => 2,
            'actions_per_session_max' => 2,
            'delay_seconds_min' => 60,
            'delay_seconds_max' => 60,
        ]);

        $random = $this->createMock(RandomIntRange::class);
        $random->method('between')->willReturnCallback(
            function (int $min, int $max): int {
                return $min === $max ? $min : $min;
            },
        );
        $this->app->instance(RandomIntRange::class, $random);

        $execute = $this->createMock(ExecuteSyntheticDuelAction::class);
        $execute->method('execute')->willReturn(new SyntheticSessionActionResult(
            actionIndex: 1,
            plannedActions: 2,
            duelId: 1,
            attributeKey: 'pace',
            playerAId: 1,
            playerBId: 2,
            decision: 'vote',
            winnerId: 1,
            status: 'ok',
        ));
        $this->app->instance(ExecuteSyntheticDuelAction::class, $execute);

        $exitCode = Artisan::call('zcout:synthetic-world:tick');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Sessions started: 1', Artisan::output());
        $this->assertDatabaseHas('synthetic_user_sessions', [
            'user_id' => $user->id,
            'activity_date' => '2026-07-18',
            'daily_session_index' => 1,
        ]);
    }

    private function bindTickResult(SyntheticWorldTickResult $result): void
    {
        $this->mock(TickSyntheticWorldAction::class, function ($mock) use ($result): void {
            $mock->shouldReceive('execute')->once()->andReturn($result);
        });
    }
}
