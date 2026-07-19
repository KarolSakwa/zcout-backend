<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyntheticWorldRuntimeSettings extends Model
{
    public const SINGLETON_ID = 1;

    public const PAUSE_FINISH_ACTIVE = 'finish_active';

    public const PAUSE_CANCEL_ACTIVE = 'cancel_active';

    protected $table = 'synthetic_world_runtime_settings';

    protected $fillable = [
        'runtime_enabled',
        'paused_at',
        'pause_mode',
        'updated_source',
        'tick_started_at',
        'tick_finished_at',
        'tick_failed_at',
        'last_error',
        'last_progress_at',
        'last_tick_duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'runtime_enabled' => 'boolean',
            'paused_at' => 'datetime',
            'tick_started_at' => 'datetime',
            'tick_finished_at' => 'datetime',
            'tick_failed_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'last_tick_duration_ms' => 'integer',
        ];
    }
}
