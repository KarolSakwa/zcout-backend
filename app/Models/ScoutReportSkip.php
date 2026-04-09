<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoutReportSkip extends Model
{
    protected $fillable = [
        'user_id',
        'player_id',
        'attribute_id',
        'skipped_at',
    ];

    protected $casts = [
        'skipped_at' => 'datetime',
    ];
}
