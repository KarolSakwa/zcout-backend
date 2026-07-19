<?php

return [
    'competition' => 'Premier League',
    'season' => env('ZCOUT_PL_SEASON', '2026/27'),
    'expected_club_count' => 20,
    'api_base' => 'https://api.football-data.org/v4',
    'api_retry_times' => 3,
    'api_retry_sleep_ms' => 800,
];
