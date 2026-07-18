<?php

namespace Tests\Unit\Simulation\Synthetic;

use App\Simulation\Synthetic\SyntheticDailyActivityPlanner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SyntheticDailyActivityPlannerTest extends TestCase
{
    private SyntheticDailyActivityPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new SyntheticDailyActivityPlanner();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_same_user_and_date_yield_same_target(): void
    {
        $a = $this->planner->targetSessionsToday(42, '2026-07-18', 1, 5);
        $b = $this->planner->targetSessionsToday(42, '2026-07-18', 1, 5);

        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(1, $a);
        $this->assertLessThanOrEqual(5, $a);
    }

    public function test_target_respects_min_max_bounds(): void
    {
        for ($userId = 1; $userId <= 40; $userId++) {
            $target = $this->planner->targetSessionsToday($userId, '2026-07-18', 2, 6);
            $this->assertGreaterThanOrEqual(2, $target);
            $this->assertLessThanOrEqual(6, $target);
        }
    }

    public function test_min_equals_max_returns_that_value(): void
    {
        $this->assertSame(3, $this->planner->targetSessionsToday(7, '2026-07-18', 3, 3));
        $this->assertSame(0, $this->planner->targetSessionsToday(7, '2026-07-18', 0, 0));
    }

    public function test_zero_target_means_no_activity(): void
    {
        $this->assertSame(0, $this->planner->targetSessionsToday(99, '2026-07-18', 0, 0));
    }

    public function test_different_date_can_change_target(): void
    {
        $targets = [];
        for ($day = 1; $day <= 40; $day++) {
            $date = sprintf('2026-07-%02d', min($day, 28));
            if ($day > 28) {
                $date = sprintf('2026-08-%02d', $day - 28);
            }
            $targets[] = $this->planner->targetSessionsToday(11, $date, 1, 8);
        }

        $this->assertGreaterThan(1, count(array_unique($targets)));
    }

    public function test_scheduled_times_are_ordered_within_day_slots(): void
    {
        config(['app.timezone' => 'UTC']);
        $userId = 15;
        $date = '2026-07-18';
        $target = 4;

        $times = [];
        for ($index = 1; $index <= $target; $index++) {
            $times[$index] = $this->planner->scheduledStartAt($userId, $date, $index, $target);
        }

        $dayStart = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $dayEnd = $dayStart->addDay();
        $totalSeconds = $dayStart->diffInSeconds($dayEnd);

        for ($index = 1; $index <= $target; $index++) {
            $time = $times[$index];
            $this->assertTrue($time->gte($dayStart));
            $this->assertTrue($time->lt($dayEnd));

            $slotStart = intdiv(($index - 1) * $totalSeconds, $target);
            $slotEnd = intdiv($index * $totalSeconds, $target);
            $offset = $dayStart->diffInSeconds($time);
            $this->assertGreaterThanOrEqual($slotStart, $offset);
            $this->assertLessThan($slotEnd, $offset);

            if ($index > 1) {
                $this->assertTrue($time->gt($times[$index - 1]));
            }
        }
    }

    public function test_same_input_yields_identical_scheduled_time(): void
    {
        $a = $this->planner->scheduledStartAt(3, '2026-07-18', 2, 5);
        $b = $this->planner->scheduledStartAt(3, '2026-07-18', 2, 5);

        $this->assertTrue($a->equalTo($b));
    }

    public function test_session_seed_is_stable_uuid(): void
    {
        $a = $this->planner->sessionSeed(9, '2026-07-18', 1);
        $b = $this->planner->sessionSeed(9, '2026-07-18', 1);

        $this->assertSame($a, $b);
        $this->assertSame(36, strlen($a));
        $this->assertNotSame(
            $a,
            $this->planner->sessionSeed(9, '2026-07-18', 2),
        );
    }

    public function test_uses_application_timezone_for_local_day(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);

        $scheduled = $this->planner->scheduledStartAt(1, '2026-07-18', 1, 1);
        $this->assertSame('Europe/Warsaw', $scheduled->timezoneName);
        $this->assertSame('2026-07-18', $scheduled->toDateString());
    }

    public function test_dst_spring_forward_day_stays_within_local_day(): void
    {
        config(['app.timezone' => 'Europe/Warsaw']);
        // Europe/Warsaw spring forward 2026-03-29: local day is 23 hours.
        $date = '2026-03-29';
        $target = 3;

        $times = [];
        for ($index = 1; $index <= $target; $index++) {
            $times[] = $this->planner->scheduledStartAt(21, $date, $index, $target);
        }

        foreach ($times as $time) {
            $this->assertSame($date, $time->timezone('Europe/Warsaw')->toDateString());
        }

        $this->assertTrue($times[0]->lt($times[1]));
        $this->assertTrue($times[1]->lt($times[2]));
    }
}
