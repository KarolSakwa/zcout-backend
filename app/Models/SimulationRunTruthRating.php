<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SimulationRunTruthRating extends Model
{
    protected $fillable = [
        'simulation_run_id',
        'player_id',
        'attribute_key',
        'truth_rating',
        'source_label',
    ];

    protected function casts(): array
    {
        return [
            'truth_rating' => 'decimal:2',
        ];
    }
}
