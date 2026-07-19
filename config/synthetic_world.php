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

];
