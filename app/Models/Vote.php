<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attribute_id',
        'player_a_id',
        'player_b_id',
        'winner_id',
        'voter_hash',
    ];
}
