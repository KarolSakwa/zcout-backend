<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

final class SyntheticWorldScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->forgetInstance(Schedule::class);

        parent::tearDown();
    }

    public function test_tick_is_not_registered_when_disabled(): void
    {
        config([
            'synthetic_world.enabled' => false,
            'synthetic_world.without_overlapping_minutes' => 1,
        ]);

        $events = $this->syntheticWorldScheduleEvents();

        $this->assertCount(0, $events);
    }

    public function test_tick_is_registered_every_ten_seconds_when_enabled(): void
    {
        config([
            'synthetic_world.enabled' => true,
            'synthetic_world.without_overlapping_minutes' => 1,
        ]);

        $events = $this->syntheticWorldScheduleEvents();

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame(10, $event->repeatSeconds);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1, $event->expiresAt);
        $this->assertStringContainsString('zcout:synthetic-world:tick', (string) $event->command);
        $this->assertSame('Synthetic world tick', $event->description);
    }

    public function test_without_overlapping_minutes_come_from_config(): void
    {
        config([
            'synthetic_world.enabled' => true,
            'synthetic_world.without_overlapping_minutes' => 3,
        ]);

        $events = $this->syntheticWorldScheduleEvents();

        $this->assertCount(1, $events);
        $this->assertSame(3, $events[0]->expiresAt);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_invalid_overlap_throws_when_enabled(): void
    {
        config([
            'synthetic_world.enabled' => true,
            'synthetic_world.without_overlapping_minutes' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('synthetic_world.without_overlapping_minutes must be an integer greater than or equal to 1');

        $this->app->make(Schedule::class);
    }

    public function test_manual_tick_command_still_invokes_action_when_environment_disabled(): void
    {
        config(['synthetic_world.enabled' => false]);

        $this->mock(\App\Simulation\Synthetic\TickSyntheticWorldAction::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturn(new \App\Simulation\Synthetic\SyntheticWorldTickResult());
        });

        $exitCode = Artisan::call('zcout:synthetic-world:tick');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synthetic world tick completed', Artisan::output());
    }

    /**
     * @return list<\Illuminate\Console\Scheduling\Event>
     */
    private function syntheticWorldScheduleEvents(): array
    {
        $this->app->forgetInstance(Schedule::class);

        return collect($this->app->make(Schedule::class)->events())
            ->filter(static fn ($event): bool => str_contains((string) $event->command, 'zcout:synthetic-world:tick'))
            ->values()
            ->all();
    }
}
