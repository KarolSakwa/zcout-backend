<?php

namespace App\PremierLeague\Support;

interface PremierLeagueApiSleeper
{
    public function sleep(float $seconds): void;
}
