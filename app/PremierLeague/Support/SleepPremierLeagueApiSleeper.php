<?php

namespace App\PremierLeague\Support;

final class SleepPremierLeagueApiSleeper implements PremierLeagueApiSleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
