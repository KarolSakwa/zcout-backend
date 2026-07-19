<?php

namespace App\PremierLeague\Support;

final class SystemPremierLeagueApiClock implements PremierLeagueApiClock
{
    public function now(): float
    {
        return microtime(true);
    }
}
