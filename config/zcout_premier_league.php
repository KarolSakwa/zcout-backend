<?php

return [
    'competition' => 'Premier League',
    'season' => env('ZCOUT_PL_SEASON', '2026/27'),
    'expected_club_count' => 20,
    'api_base' => 'https://api.football-data.org/v4',
    'api' => [
        'minimum_request_interval_seconds' => (int) env('ZCOUT_PL_API_MIN_INTERVAL', 7),
        'max_requests_per_minute' => (int) env('ZCOUT_PL_API_MAX_REQUESTS_PER_MINUTE', 9),
        'rate_limit_window_seconds' => (int) env('ZCOUT_PL_API_RATE_LIMIT_WINDOW', 60),
        'rate_limit_retry_margin_seconds' => (int) env('ZCOUT_PL_API_RATE_LIMIT_MARGIN', 2),
        'max_rate_limit_retries' => (int) env('ZCOUT_PL_API_MAX_RATE_LIMIT_RETRIES', 3),
        'rate_limit_fallback_wait_seconds' => (int) env('ZCOUT_PL_API_RATE_LIMIT_FALLBACK', 60),
    ],
    'player_external_id_remaps' => [
        186701 => 191154, // Stefan Bajcetic
        176852 => 191140, // Lewis Hall
        180389 => 191396, // Adam Wharton
    ],
];
