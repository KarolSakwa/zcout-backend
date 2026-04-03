<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SimulationRun extends Model
{
    protected $fillable = [
        'mode',
        'status',
        'label',
        'config',
        'started_at',
        'finished_at',
        'result',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(SimulationRunEvent::class);
    }

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function truthRatings(): HasMany
    {
        return $this->hasMany(SimulationRunTruthRating::class);
    }
}
