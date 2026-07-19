<?php

namespace Tests\Support;

use App\PremierLeague\Support\PremierLeagueApiClock;
use App\PremierLeague\Support\PremierLeagueApiSleeper;

final class FakePremierLeagueApiTimer implements PremierLeagueApiClock, PremierLeagueApiSleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(
        private float $now = 0.0,
    ) {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }
}
