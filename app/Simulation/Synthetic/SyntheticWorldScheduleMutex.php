<?php

namespace App\Simulation\Synthetic;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

class SyntheticWorldScheduleMutex
{
    public const STALE_TICK_SECONDS = 120;

    public function __construct(
        private readonly SyntheticWorldRuntime $runtime,
    ) {
    }

    public function mutexName(): ?string
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) ($event->command ?? ''), 'zcout:synthetic-world:tick')) {
                return $event->mutexName();
            }
        }

        // Schedule may omit the event when environment automation is disabled.
        $schedule = app(Schedule::class);
        $probe = $schedule->command('zcout:synthetic-world:tick')
            ->everyTenSeconds()
            ->withoutOverlapping(max(1, (int) config('synthetic_world.without_overlapping_minutes', 1)));

        return $probe->mutexName();
    }

    public function exists(): bool
    {
        $name = $this->mutexName();
        if ($name === null) {
            return false;
        }

        $lock = Cache::store('redis')->lock($name, 1);
        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    public function isStale(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        return $this->heartbeatIndicatesStale();
    }

    public function heartbeatIndicatesStale(): bool
    {
        $settings = $this->runtime->current();
        if ($settings->tick_started_at === null) {
            return false;
        }

        $startedAge = $settings->tick_started_at->diffInSeconds(now());
        if ($startedAge < self::STALE_TICK_SECONDS) {
            return false;
        }

        if ($settings->tick_finished_at !== null && $settings->tick_finished_at->gte($settings->tick_started_at)) {
            return false;
        }

        return true;
    }

    public function ageSeconds(): ?int
    {
        if (! $this->exists()) {
            return null;
        }

        $settings = $this->runtime->current();
        if ($settings->tick_started_at === null) {
            return null;
        }

        return (int) $settings->tick_started_at->diffInSeconds(now());
    }

    /**
     * @return array{cleared: bool, reason: string}
     */
    public function clearIfStale(): array
    {
        if (! $this->exists()) {
            return ['cleared' => false, 'reason' => 'mutex_absent'];
        }

        if (! $this->isStale()) {
            return ['cleared' => false, 'reason' => 'mutex_not_stale'];
        }

        $name = $this->mutexName();
        if ($name === null) {
            return ['cleared' => false, 'reason' => 'mutex_name_unavailable'];
        }

        $store = Cache::store('redis')->getStore();
        if ($store instanceof LockProvider) {
            $store->lock($name, 1)->forceRelease();
        } else {
            Cache::store('redis')->forget($name);
        }

        return ['cleared' => true, 'reason' => 'stale_mutex_cleared'];
    }
}
