<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Synthetic World automation
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel Scheduler runs zcout:synthetic-world:tick every
    | 10 seconds (fixed MVP interval). Manual artisan invocations ignore
    | this flag.
    |
    */

    'enabled' => (bool) env('SYNTHETIC_WORLD_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | withoutOverlapping lock expiry (minutes)
    |--------------------------------------------------------------------------
    |
    | Passed to Schedule::withoutOverlapping($minutes). Prevents a new tick
    | from starting while the previous tick still holds the mutex.
    |
    */

    'without_overlapping_minutes' => (int) env('SYNTHETIC_WORLD_WITHOUT_OVERLAPPING_MINUTES', 1),

    /*
    |--------------------------------------------------------------------------
    | Daily activity window
    |--------------------------------------------------------------------------
    |
    | Synthetic sessions are scheduled only inside this local-time window.
    | The end hour is exclusive, so 07:00–18:00 allows activity until 17:59:59.
    |
    */

    'activity_start_hour' => (int) env('SYNTHETIC_WORLD_ACTIVITY_START_HOUR', 7),

    'activity_end_hour' => (int) env('SYNTHETIC_WORLD_ACTIVITY_END_HOUR', 18),

    'actions_per_tick' => (int) env('SYNTHETIC_WORLD_ACTIONS_PER_TICK', 1),
    
];
