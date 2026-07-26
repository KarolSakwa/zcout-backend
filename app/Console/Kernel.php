<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use InvalidArgumentException;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->useCache('redis');
        $schedule->command('zcout:sync-football-data-player-metadata')->weeklyOn(1, '03:00');

        $this->scheduleSyntheticWorldTick($schedule);
    }

    /**
     * Register the Synthetic World tick when automation is enabled.
     */
    protected function scheduleSyntheticWorldTick(Schedule $schedule): void
    {
        if (! config('synthetic_world.enabled')) {
            return;
        }

        $overlapMinutes = (int) config('synthetic_world.without_overlapping_minutes');
        if ($overlapMinutes < 1) {
            throw new InvalidArgumentException(
                'synthetic_world.without_overlapping_minutes must be an integer greater than or equal to 1.',
            );
        }

        $actionsPerTick = (int) config('synthetic_world.actions_per_tick', 1);
        $startHour = (int) config('synthetic_world.activity_start_hour', 7);
        $endHour = (int) config('synthetic_world.activity_end_hour', 18);

        if ($actionsPerTick < 1) {
            throw new InvalidArgumentException(
                'synthetic_world.actions_per_tick must be an integer greater than or equal to 1.',
            );
        }

        $schedule->command("zcout:synthetic-world:tick --session-limit={$actionsPerTick}")
            ->everyTenSeconds()
            ->between(
                sprintf('%02d:00', $startHour),
                sprintf('%02d:59', $endHour - 1),
            )
            ->withoutOverlapping($overlapMinutes)
            ->name('synthetic-world-tick')
            ->description('Synthetic world tick');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
