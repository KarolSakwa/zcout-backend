<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SimulationRunEvent extends Model
{
    protected $fillable = [
        'simulation_run_id',
        'sequence',
        'source',
        'event_type',
        'simulated_user_id',
        'is_logged',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'is_logged' => 'boolean',
            'payload' => 'array',
        ];
    }
}
