<?php

namespace Tests\Unit\PremierLeague\Support;

use App\PremierLeague\Support\FootballDataRequestThrottler;
use Tests\Support\FakePremierLeagueApiTimer;
use Tests\TestCase;

class FootballDataRequestThrottlerTest extends TestCase
{
    public function test_it_enforces_minimum_interval_between_requests(): void
    {
        $timer = new FakePremierLeagueApiTimer();
        $throttler = new FootballDataRequestThrottler($timer, $timer, 7, 9, 60);

        $throttler->waitBeforeNextRequest();
        $throttler->recordRequest();

        $throttler->waitBeforeNextRequest();
        $throttler->recordRequest();

        $this->assertSame([7.0], $timer->sleeps);
    }

    public function test_full_sync_dataset_requests_respect_minimum_interval(): void
    {
        $timer = new FakePremierLeagueApiTimer();
        $throttler = new FootballDataRequestThrottler($timer, $timer, 7, 9, 60);

        for ($i = 0; $i < 21; $i++) {
            $throttler->waitBeforeNextRequest();
            $throttler->recordRequest();
        }

        $this->assertSame(20, count($timer->sleeps));
        $this->assertEqualsWithDelta(140.0, array_sum($timer->sleeps), 0.001);
        $this->assertSame(140.0, $timer->now());
        $this->assertLessThanOrEqual(9, $this->maxRequestsInAnyWindow($throttler->requestTimestamps(), 60));
    }

    public function test_it_waits_for_rate_limit_window_before_tenth_request_in_minute(): void
    {
        $timer = new FakePremierLeagueApiTimer();
        $throttler = new FootballDataRequestThrottler($timer, $timer, 0, 9, 60);

        for ($i = 0; $i < 9; $i++) {
            $throttler->waitBeforeNextRequest();
            $throttler->recordRequest();
        }

        $throttler->waitBeforeNextRequest();

        $this->assertNotEmpty($timer->sleeps);
        $this->assertGreaterThanOrEqual(60.0, max($timer->sleeps));
    }

    /**
     * @param  list<float>  $timestamps
     */
    private function maxRequestsInAnyWindow(array $timestamps, int $windowSeconds): int
    {
        $max = 0;

        foreach ($timestamps as $index => $timestamp) {
            $count = 0;
            foreach ($timestamps as $other) {
                if ($other >= $timestamp && $other <= $timestamp + $windowSeconds) {
                    $count++;
                }
            }

            $max = max($max, $count);
        }

        return $max;
    }
}
