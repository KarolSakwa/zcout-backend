<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SimulationRun extends Model
{
    protected $fillable = [
        'mode',
        'status',
        'config',
        'started_at',
        'finished_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
